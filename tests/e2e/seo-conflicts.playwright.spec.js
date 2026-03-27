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

async function updatePageViaRest(page, postId, payload) {
	return page.evaluate(async ({ id, data }) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce) {
			throw new Error('REST nonce unavailable.');
		}
		const response = await fetch(`${window.location.origin}/wp-json/wp/v2/pages/${Number(id)}`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify(data)
		});
		if (!response.ok) {
			throw new Error(`Page update failed (${response.status}).`);
		}
		return response.json();
	}, { id: postId, data: payload });
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

async function saveSearchAppearanceSettings(page, updates) {
	await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-search-appearance`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('form input[name="ogs_seo_action"][value="save_settings"]', { timeout: 20000, state: 'attached' });
	for (const [name, value] of Object.entries(updates)) {
		const input = page.locator(`[name="ogs[${name}]"]`).first();
		if ((await input.count()) === 0) {
			continue;
		}
		const tag = await input.evaluate((el) => el.tagName.toLowerCase());
		if (tag === 'select') {
			await input.selectOption(String(value));
		} else {
			await input.fill(String(value));
		}
	}
	await Promise.all([
		page.waitForLoadState('domcontentloaded'),
		page.locator('form button.button-primary:has-text("Save changes")').first().click()
	]);
}

async function addRedirectRule(page, sourcePath, destinationUrl) {
	await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-redirects`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('#ogs_redirect_source_path', { timeout: 20000 });
	await page.fill('#ogs_redirect_source_path', sourcePath);
	await page.fill('#ogs_redirect_destination_url', destinationUrl);
	await page.selectOption('#ogs_redirect_match_type', 'exact');
	await page.selectOption('#ogs_redirect_status_code', '301');
	await Promise.all([
		page.waitForLoadState('domcontentloaded'),
		page.locator('button.button.button-primary:has-text("Add redirect rule")').click()
	]);
}

test.describe('Open Growth SEO conflict matrix', () => {
	test('duplicate canonical set stays deterministic in frontend output', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let sourceId = 0;
		let targetId = 0;
		try {
			const unique = Date.now();
			const target = await createPageViaRest(page, {
				title: `Canonical target ${unique}`,
				content: 'Canonical destination page.',
				status: 'publish'
			});
			const source = await createPageViaRest(page, {
				title: `Canonical source ${unique}`,
				content: 'Duplicate variant that should canonicalize.',
				status: 'publish'
			});
			targetId = Number(target.id || 0);
			sourceId = Number(source.id || 0);
			expect(targetId).toBeGreaterThan(0);
			expect(sourceId).toBeGreaterThan(0);

			await updateContentMeta(page, sourceId, {
				ogs_seo_canonical: target.link
			});

			await page.goto(source.link, { waitUntil: 'domcontentloaded' });
			const canonicalHref = await page.locator('link[rel="canonical"]').first().getAttribute('href');
			expect(canonicalHref).toBeTruthy();
			expect(new URL(String(canonicalHref), BASE_URL).pathname).toBe(new URL(target.link).pathname);

		} finally {
			if (sourceId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, sourceId);
			}
			if (targetId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, targetId);
			}
		}
	});

	test('redirected legacy URL keeps runtime behavior coherent', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let destinationId = 0;
		try {
			const unique = Date.now();
			const destination = await createPageViaRest(page, {
				title: `Redirect destination ${unique}`,
				slug: `redirect-destination-${unique}`,
				content: 'Destination content.',
				status: 'publish'
			});
			destinationId = Number(destination.id || 0);
			const sourcePath = `/redirect-legacy-${unique}/`;
			await addRedirectRule(page, sourcePath, destination.link);

			await page.goto(`${BASE_URL}${sourcePath}`, { waitUntil: 'domcontentloaded' });
			await expect(page).toHaveURL(destination.link);
		} finally {
			if (destinationId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, destinationId);
			}
		}
	});

	test('redirected legacy URL does not leak stale canonical metadata', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let destinationId = 0;
		let legacyId = 0;
		try {
			const unique = Date.now();
			const destination = await createPageViaRest(page, {
				title: `Redirect canonical destination ${unique}`,
				slug: `redirect-canonical-destination-${unique}`,
				content: 'Canonical destination content.',
				status: 'publish'
			});
			destinationId = Number(destination.id || 0);

			const legacySlug = `legacy-with-canonical-${unique}`;
			const legacy = await createPageViaRest(page, {
				title: `Legacy canonical source ${unique}`,
				slug: legacySlug,
				content: 'Legacy source content.',
				status: 'publish'
			});
			legacyId = Number(legacy.id || 0);
			expect(destinationId).toBeGreaterThan(0);
			expect(legacyId).toBeGreaterThan(0);

			const staleCanonical = `${BASE_URL}/stale-canonical-target-${unique}/`;
			await updateContentMeta(page, legacyId, {
				ogs_seo_canonical: staleCanonical
			});
			await deletePageViaRest(page, legacyId);
			legacyId = 0;

			const sourcePath = `/${legacySlug}/`;
			await addRedirectRule(page, sourcePath, destination.link);

			await page.goto(`${BASE_URL}${sourcePath}`, { waitUntil: 'domcontentloaded' });
			await expect(page).toHaveURL(destination.link);
			const canonicalHref = await page.locator('link[rel="canonical"]').first().getAttribute('href');
			expect(canonicalHref).toBeTruthy();
			expect(new URL(String(canonicalHref), BASE_URL).pathname).toBe(new URL(destination.link).pathname);
			expect(String(canonicalHref)).not.toContain(`stale-canonical-target-${unique}`);
		} finally {
			if (legacyId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, legacyId);
			}
			if (destinationId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, destinationId);
			}
		}
	});

	test('noindex pages do not keep stale ineligible schema output', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const post = await createPageViaRest(page, {
				title: `Noindex schema ${unique}`,
				content: 'Simple body without FAQ pairs or job signals.',
				status: 'publish'
			});
			postId = Number(post.id || 0);

			await updateContentMeta(page, postId, {
				ogs_seo_index: 'noindex',
				ogs_seo_follow: 'follow',
				ogs_seo_schema_type: 'FAQPage'
			});

			await page.goto(post.link, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/i);
			const html = await page.content();
			expect(html).not.toContain('"@type":"FAQPage"');
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('hidden FAQ markup does not pass representativeness checks or emit FAQPage schema', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const post = await createPageViaRest(page, {
				title: `Hidden FAQ ${unique}`,
				content: '<div style="display:none"><h2>What is hidden?</h2><p>Hidden answer one.</p><h2>Why hidden?</h2><p>Hidden answer two.</p></div><p>Visible body only.</p>',
				status: 'publish'
			});
			postId = Number(post.id || 0);

			await updateContentMeta(page, postId, {
				ogs_seo_schema_type: 'FAQPage'
			});

			await page.goto(post.link, { waitUntil: 'domcontentloaded' });
			const html = await page.content();
			expect(html).not.toContain('"@type":"FAQPage"');
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('social overrides persist to frontend while ineligible schema override is suppressed', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const socialImage = `${BASE_URL}/wp-content/uploads/conflict-social-${unique}.jpg`;
			const post = await createPageViaRest(page, {
				title: `Social conflict ${unique}`,
				content: 'Content focused on product support basics.',
				status: 'publish'
			});
			postId = Number(post.id || 0);

			await updateContentMeta(page, postId, {
				ogs_seo_social_title: `Social title ${unique}`,
				ogs_seo_social_description: `Social description ${unique}`,
				ogs_seo_social_image: socialImage,
				ogs_seo_schema_type: 'JobPosting'
			});

			await page.goto(post.link, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('meta[property="og:title"]')).toHaveAttribute('content', `Social title ${unique}`);
			await expect(page.locator('meta[property="og:description"]')).toHaveAttribute('content', new RegExp(`Social description ${unique}`));
			await expect(page.locator('meta[property="og:image"]')).toHaveAttribute('content', socialImage);
			const html = await page.content();
			expect(html).not.toContain('"@type":"JobPosting"');
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('canonical diagnostics surfaces noindex + manual canonical conflicts in admin', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let sourceId = 0;
		let targetId = 0;
		try {
			const unique = Date.now();
			const target = await createPageViaRest(page, {
				title: `Noindex canonical target ${unique}`,
				content: 'Canonical target body.',
				status: 'publish'
			});
			const source = await createPageViaRest(page, {
				title: `Noindex canonical source ${unique}`,
				content: 'Source page body for canonical conflict diagnostics.',
				status: 'publish'
			});
			targetId = Number(target.id || 0);
			sourceId = Number(source.id || 0);
			expect(targetId).toBeGreaterThan(0);
			expect(sourceId).toBeGreaterThan(0);

			await updateContentMeta(page, sourceId, {
				ogs_seo_canonical: target.link,
				ogs_seo_index: 'noindex',
				ogs_seo_follow: 'follow'
			});

			await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-masters-plus&ogs_show_advanced=1`, { waitUntil: 'domcontentloaded' });
			await expect(page.getByRole('heading', { name: 'Canonical Policy Diagnostics' })).toBeVisible();
			const row = page.locator('table.widefat tbody tr', { hasText: `Noindex canonical source ${unique}` }).first();
			await expect(row).toBeVisible();
			await expect(row).toContainText(/noindex/i);
		} finally {
			if (sourceId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, sourceId);
			}
			if (targetId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, targetId);
			}
		}
	});

	test('canonical diagnostics catches redirect/manual canonical conflicts with deterministic effective output', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let sourceId = 0;
		let manualTargetId = 0;
		let redirectTargetId = 0;
		try {
			const unique = Date.now();
			const source = await createPageViaRest(page, {
				title: `Canonical redirect source ${unique}`,
				slug: `canonical-redirect-source-${unique}`,
				content: 'Source body.',
				status: 'publish'
			});
			const manualTarget = await createPageViaRest(page, {
				title: `Canonical manual target ${unique}`,
				slug: `canonical-manual-target-${unique}`,
				content: 'Manual canonical target body.',
				status: 'publish'
			});
			const redirectTarget = await createPageViaRest(page, {
				title: `Canonical redirect target ${unique}`,
				slug: `canonical-redirect-target-${unique}`,
				content: 'Redirect canonical target body.',
				status: 'publish'
			});
			sourceId = Number(source.id || 0);
			manualTargetId = Number(manualTarget.id || 0);
			redirectTargetId = Number(redirectTarget.id || 0);
			expect(sourceId).toBeGreaterThan(0);
			expect(manualTargetId).toBeGreaterThan(0);
			expect(redirectTargetId).toBeGreaterThan(0);

			await updateContentMeta(page, sourceId, {
				ogs_seo_canonical: manualTarget.link
			});
			await addRedirectRule(page, `/canonical-redirect-source-${unique}/`, redirectTarget.link);

			await page.goto(source.link, { waitUntil: 'domcontentloaded' });
			const canonicalHref = await page.locator('link[rel="canonical"]').first().getAttribute('href');
			expect(canonicalHref).toBeTruthy();
			expect(new URL(String(canonicalHref), BASE_URL).pathname).toBe(new URL(redirectTarget.link).pathname);

			await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-masters-plus&ogs_show_advanced=1`, { waitUntil: 'domcontentloaded' });
			const row = page.locator('table.widefat tbody tr', { hasText: `Canonical redirect source ${unique}` }).first();
			await expect(row).toBeVisible();
			await expect(row).toContainText(/redirect/i);
			await expect(row).toContainText(/different URL/i);
		} finally {
			if (sourceId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, sourceId);
			}
			if (manualTargetId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, manualTargetId);
			}
			if (redirectTargetId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, redirectTargetId);
			}
		}
	});

	test('breadcrumb frontend output follows saved settings changes', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			await saveSearchAppearanceSettings(page, {
				breadcrumbs_home_label: 'Portal'
			});
			await openEditorNonceContext(page);
			const post = await createPageViaRest(page, {
				title: `Breadcrumb conflict ${unique}`,
				content: '[ogs_seo_breadcrumbs]',
				status: 'publish'
			});
			postId = Number(post.id || 0);
			await page.goto(post.link, { waitUntil: 'domcontentloaded' });
			await expect(page.locator('nav.ogs-seo-breadcrumbs li').first()).toContainText('Portal');
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('runtime inspector records history and diffs after schema-relevant content changes', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let postId = 0;
		try {
			const unique = Date.now();
			const post = await createPageViaRest(page, {
				title: `Inspector history ${unique}`,
				content: '<p>Initial content only.</p>',
				status: 'publish'
			});
			postId = Number(post.id || 0);
			expect(postId).toBeGreaterThan(0);

			await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-schema&schema_tab=runtime&schema_inspect_post_id=${postId}`, { waitUntil: 'domcontentloaded' });
			await expect(page.getByRole('heading', { name: 'Inspector snapshot history' })).toBeVisible();

			await openEditorNonceContext(page);
			await updatePageViaRest(page, postId, {
				content: '<!-- wp:heading --><h2>What changed?</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Answer one is now visible.</p><!-- /wp:paragraph --><!-- wp:heading --><h2>Why does it matter?</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Answer two is now visible.</p><!-- /wp:paragraph -->'
			});
			await updateContentMeta(page, postId, {
				ogs_seo_schema_type: 'FAQPage'
			});

			await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-schema&schema_tab=runtime&schema_inspect_post_id=${postId}`, { waitUntil: 'domcontentloaded' });
			await expect(page.getByRole('heading', { name: 'Inspection diff vs previous snapshot' })).toBeVisible();
			await expect(page.locator('text=Payload changed')).toBeVisible();
			await expect(page.locator('table.widefat tbody tr', { hasText: 'Unexpected emitted nodes' }).first()).toBeVisible();
		} finally {
			if (postId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, postId);
			}
		}
	});

	test('search results canonical keeps query intent and strips tracking params while staying noindex', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		const unique = `canonical-search-${Date.now()}`;
		await saveSearchAppearanceSettings(page, {
			search_results: 'noindex'
		});

		await page.goto(`${BASE_URL}/?s=${encodeURIComponent(unique)}&utm_source=e2e&utm_medium=test`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/i);
		const canonicalHref = await page.locator('link[rel="canonical"]').first().getAttribute('href');
		expect(canonicalHref).toBeTruthy();
		const canonicalUrl = new URL(String(canonicalHref), BASE_URL);
		if (canonicalUrl.searchParams.has('s')) {
			expect(canonicalUrl.searchParams.get('s')).toBe(unique);
		}
		expect(canonicalUrl.searchParams.get('utm_source')).toBeNull();
		expect(canonicalUrl.searchParams.get('utm_medium')).toBeNull();
	});

	test('Woo archive filter canonical/robots policy is consistent when WooCommerce is active', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });
		const hasWooMenu = (await page.locator('#menu-posts-product').count()) > 0;
		test.skip(!hasWooMenu, 'WooCommerce is not active in this runtime.');

		await saveSearchAppearanceSettings(page, {
			woo_filter_results: 'noindex',
			woo_filter_canonical_target: 'base'
		});

		await page.goto(`${BASE_URL}/shop/?orderby=price`, { waitUntil: 'domcontentloaded' });
		const canonical = page.locator('link[rel="canonical"]').first();
		const href = await canonical.getAttribute('href');
		expect(href || '').not.toContain('orderby=');
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/i);
	});

	test('Woo archive filter policy can suppress canonical output when explicitly configured', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });
		const hasWooMenu = (await page.locator('#menu-posts-product').count()) > 0;
		test.skip(!hasWooMenu, 'WooCommerce is not active in this runtime.');

		await saveSearchAppearanceSettings(page, {
			woo_filter_results: 'noindex',
			woo_filter_canonical_target: 'none'
		});

		await page.goto(`${BASE_URL}/shop/?orderby=price`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('meta[name="robots"]')).toHaveAttribute('content', /noindex/i);
		await expect(page.locator('link[rel="canonical"]')).toHaveCount(0);
	});
});
