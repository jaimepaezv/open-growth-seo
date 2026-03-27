const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

async function ensureLoggedIn(page) {
	await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	const loginInput = page.locator('#user_login');
	if ((await loginInput.count()) === 0) {
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
		throw new Error('REST nonce unavailable in admin editor context.');
	}
}

async function createPageViaRest(page, payload) {
	return page.evaluate(async (data) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce) {
			throw new Error('REST nonce unavailable.');
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
			throw new Error(`Page create failed (${response.status}).`);
		}
		return response.json();
	}, payload);
}

async function updateContentMeta(page, postId, payload) {
	return page.evaluate(async ({ id, meta }) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce) {
			throw new Error('REST nonce unavailable.');
		}
		const response = await fetch(`${window.location.origin}/wp-json/ogs-seo/v1/content-meta/${Number(id)}`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify({ meta })
		});
		if (!response.ok) {
			throw new Error(`content-meta update failed (${response.status}).`);
		}
		return response.json();
	}, { id: postId, meta: payload });
}

async function deletePageViaRest(page, postId) {
	return page.evaluate(async (id) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce || !id) {
			return false;
		}
		const response = await fetch(`${window.location.origin}/wp-json/wp/v2/pages/${Number(id)}?force=true`, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': nonce }
		});
		return response.ok;
	}, postId);
}

test.describe('Open Growth SEO SFO workspace', () => {
	test('sfo workspace shows overview, telemetry, and per-page coaching cards', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const created = await createPageViaRest(page, {
				title: `SFO workspace sample ${unique}`,
				content: '<p>Technical SEO audit readiness means a page answers quickly, supports schema, and frames its snippet clearly.</p><h2>Checklist</h2><p>Requirement: XML sitemap coverage and canonical consistency.</p><h2>Answer</h2><p>Use a concise title, supporting description, and visible answer block.</p>',
				status: 'publish'
			});
			postId = Number(created.id || 0);
			expect(postId).toBeGreaterThan(0);

			await updateContentMeta(page, postId, {
				ogs_seo_title: 'SFO Workspace Sample for Technical SEO Audits',
				ogs_seo_description: 'Improve search feature readiness with clearer snippets, direct answers, and schema-safe content structure.',
				ogs_seo_schema_type: 'Article',
				ogs_seo_social_title: 'Technical SEO audit readiness',
				ogs_seo_social_image: 'https://example.com/image.jpg'
			});

			await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-sfo`, { waitUntil: 'domcontentloaded' });
			await expect(page.getByText('Average SFO score')).toBeVisible();
			await expect(page.getByText('Cross-page SFO rollup')).toBeVisible();
			await expect(page.getByText('Recent SFO snapshot history')).toBeVisible();
			await expect(page.getByRole('button', { name: /REST SFO Telemetry/i })).toBeVisible();

			const card = page.locator('.ogs-coaching-card', { hasText: `SFO workspace sample ${unique}` }).first();
			await expect(card).toBeVisible();
			await expect(card).toContainText(/SFO score:/i);
			await expect(card).toContainText(/Feature readiness:/i);
			await expect(card.getByRole('link', { name: 'Inspect schema runtime' })).toBeVisible();
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, postId);
			}
		}
	});
});
