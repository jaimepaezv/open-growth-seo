const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';
const REQUIRE_CLASSIC = process.env.OGS_E2E_REQUIRE_CLASSIC === '1';

async function ensureLoggedIn(page) {
	await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	let requiresLogin = false;
	try {
		await page.waitForSelector('#user_login', { timeout: 2500 });
		requiresLogin = true;
	} catch (error) {
		requiresLogin = false;
	}

	if (!requiresLogin) {
		return;
	}

	await page.fill('#user_login', ADMIN_USER);
	await page.fill('#user_pass', ADMIN_PASS);
	await Promise.all([
		page.waitForURL(/wp-admin/, { timeout: 20000 }),
		page.click('#wp-submit')
	]);
}

async function openEditorNonceContext(page) {
	await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('body', { timeout: 30000 });
	const nonce = await page.evaluate(() => (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '');
	if (!nonce) {
		throw new Error('REST nonce is unavailable in admin editor context.');
	}
	const hasBlockEditor = (await page.locator('body.block-editor-page').count()) > 0;
	return {
		mode: hasBlockEditor ? 'gutenberg' : 'classic'
	};
}

async function createPageViaRest(page, payload) {
	return page.evaluate(async (data) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce) {
			throw new Error('REST nonce is unavailable in editor context.');
		}

		const response = await fetch(`${window.location.origin}/wp-json/wp/v2/pages`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify(data)
		});
		if (!response.ok) {
			throw new Error(`Page create failed (${response.status})`);
		}
		return response.json();
	}, payload);
}

async function deletePageViaRest(page, postId) {
	return page.evaluate(async (id) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce || !id) {
			return false;
		}
		const response = await fetch(`${window.location.origin}/wp-json/wp/v2/pages/${Number(id)}?force=true`, {
			method: 'DELETE',
			headers: {
				'X-WP-Nonce': nonce
			}
		});
		return response.ok;
	}, postId);
}

async function openSeoPanel(page) {
	const settingsToggle = page.getByRole('button', { name: 'Settings' }).first();
	if (await settingsToggle.count()) {
		const pressed = await settingsToggle.getAttribute('aria-pressed');
		if (pressed === 'false') {
			await settingsToggle.click();
		}
	}

	const panelToggle = page.locator('button.components-panel__body-toggle:has-text("Open Growth SEO")').first();
	await expect(panelToggle).toBeVisible({ timeout: 20000 });
	const expanded = await panelToggle.getAttribute('aria-expanded');
	if (expanded === 'false') {
		await panelToggle.click();
	}
}

async function ensureClassicRuntime(page, postId) {
	await page.goto(`${BASE_URL}/wp-admin/post.php?post=${postId}&action=edit&classic-editor`, { waitUntil: 'domcontentloaded' });
	const hasClassic = (await page.locator('#title').count()) > 0 && (await page.locator('input[name="ogs_seo_title"]').count()) > 0;
	if (hasClassic) {
		return;
	}

	const reason = 'Classic editor runtime unavailable. Provision Classic Editor plugin in the E2E environment.';
	if (REQUIRE_CLASSIC) {
		throw new Error(reason);
	}
	test.skip(true, `${reason} Set OGS_E2E_REQUIRE_CLASSIC=1 to hard-fail when provisioning is missing.`);
}

test.describe('Open Growth SEO Editor Runtime', () => {
	test('Gutenberg saves and reloads Open Growth SEO controls', async ({ page }) => {
		test.setTimeout(90000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const draft = await createPageViaRest(page, {
				title: `E2E Gutenberg SEO ${unique}`,
				content: 'Draft content for SEO controls runtime test.',
				status: 'draft'
			});
			postId = Number(draft && draft.id ? draft.id : 0);
			expect(postId).toBeGreaterThan(0);

			await page.goto(`${BASE_URL}/wp-admin/post.php?post=${postId}&action=edit`, { waitUntil: 'domcontentloaded' });
			const hasBlockEditor = (await page.locator('body.block-editor-page').count()) > 0;
			test.skip(!hasBlockEditor, 'Gutenberg runtime unavailable because Classic is configured as the active editor.');
			await page.waitForSelector('body.block-editor-page', { timeout: 30000 });
			await openSeoPanel(page);

			const seoTitle = `Gutenberg SEO Title ${unique}`;
			const socialImage = `${BASE_URL}/wp-content/uploads/social-${unique}.jpg`;

			await page.locator('.ogs-gutenberg-field-seo-title input').first().fill(seoTitle);
			await page.locator('.ogs-gutenberg-field-social-image input').first().fill(socialImage);

			const metaSave = page.waitForResponse(
				(response) => response.url().includes(`/wp-json/ogs-seo/v1/content-meta/${postId}`) && response.request().method() === 'POST',
				{ timeout: 30000 }
			);
			const saveButton = page.getByRole('button', { name: /Save draft|Save/ }).first();
			await saveButton.click();
			await metaSave;

			await page.reload({ waitUntil: 'domcontentloaded' });
			await page.waitForSelector('body.block-editor-page', { timeout: 30000 });
			await openSeoPanel(page);

			await expect(page.locator('.ogs-gutenberg-field-seo-title input').first()).toHaveValue(seoTitle);
			await expect(page.locator('.ogs-gutenberg-field-social-image input').first()).toHaveValue(socialImage);
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await page.waitForSelector('body', { timeout: 30000 });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('Classic editor keeps Open Growth SEO values after update', async ({ page }) => {
		test.setTimeout(90000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const draft = await createPageViaRest(page, {
				title: `E2E Classic SEO ${unique}`,
				content: 'Classic editor persistence test.',
				status: 'draft'
			});
			postId = Number(draft && draft.id ? draft.id : 0);
			expect(postId).toBeGreaterThan(0);

			await ensureClassicRuntime(page, postId);

			const seoTitle = `Classic SEO Title ${unique}`;
			const socialImage = `${BASE_URL}/wp-content/uploads/classic-social-${unique}.jpg`;

			await page.locator('#title').fill(`Classic title ${unique}`);
			await page.locator('input[name="ogs_seo_title"]').fill(seoTitle);
			await page.locator('input[name="ogs_seo_social_image"]').fill(socialImage);

			await Promise.all([
				page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				page.locator('#publish').click()
			]);

			await page.reload({ waitUntil: 'domcontentloaded' });
			await expect(page.locator('input[name="ogs_seo_title"]')).toHaveValue(seoTitle);
			await expect(page.locator('input[name="ogs_seo_social_image"]')).toHaveValue(socialImage);
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await page.waitForSelector('body', { timeout: 30000 });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('Frontend renders breadcrumb shortcode runtime safely', async ({ page }) => {
		test.setTimeout(90000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const published = await createPageViaRest(page, {
				title: `E2E Breadcrumb Runtime ${unique}`,
				content: '[ogs_seo_breadcrumbs]',
				status: 'publish'
			});
			postId = Number(published && published.id ? published.id : 0);

			const permalink = published && published.link ? String(published.link) : '';
			expect(permalink).not.toBe('');

			await page.goto(`${permalink}?e2e=${unique}`, { waitUntil: 'domcontentloaded' });
			const breadcrumb = page.locator('nav.ogs-seo-breadcrumbs');
			await expect(breadcrumb).toBeVisible();
			await expect(breadcrumb.locator('ol.ogs-seo-breadcrumbs__list > li')).toHaveCount(2);
			await expect(breadcrumb.locator('ol.ogs-seo-breadcrumbs__list > li').last().locator('[aria-current="page"]')).toBeVisible();
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await page.waitForSelector('body', { timeout: 30000 });
				await deletePageViaRest(page, postId);
			}
		}
	});
});
