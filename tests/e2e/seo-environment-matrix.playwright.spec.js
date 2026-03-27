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

async function detectEnvironmentSignature(page) {
	await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('body', { timeout: 20000 });
	const multisite = (await page.locator('#wp-admin-bar-my-sites').count()) > 0;

	await page.goto(`${BASE_URL}/wp-admin/themes.php`, { waitUntil: 'domcontentloaded' });
	const theme = ((await page.locator('.theme.active .theme-name').first().textContent()) || '').trim();

	await page.goto(`${BASE_URL}/wp-admin/plugins.php`, { waitUntil: 'domcontentloaded' });
	const activePlugins = await page.locator('tr.active .plugin-title strong').evaluateAll((nodes) => nodes.map((node) => (node.textContent || '').trim()).filter(Boolean));

	const normalized = activePlugins.map((name) => name.toLowerCase());

	return {
		multisite,
		theme,
		activePlugins,
		flags: {
			woocommerce: normalized.some((name) => name.includes('woocommerce')),
			classicEditor: normalized.some((name) => name.includes('classic editor')),
			yoast: normalized.some((name) => name.includes('yoast')),
			rankMath: normalized.some((name) => name.includes('rank math')),
			aioseo: normalized.some((name) => name.includes('all in one seo') || name.includes('aioseo')),
			redirection: normalized.some((name) => name.includes('redirection'))
		}
	};
}

test.describe('Open Growth SEO environment matrix', () => {
	test('detects explicit environment signature for the current matrix run', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		const signature = await detectEnvironmentSignature(page);

		expect(typeof signature.multisite).toBe('boolean');
		expect(signature.theme.length).toBeGreaterThan(0);
		expect(Array.isArray(signature.activePlugins)).toBeTruthy();

		const expectedMultisite = process.env.OGS_E2E_EXPECT_MULTISITE;
		if (expectedMultisite === '1' || expectedMultisite === '0') {
			expect(signature.multisite).toBe(expectedMultisite === '1');
		}

		const expectedTheme = (process.env.OGS_E2E_EXPECT_THEME || '').trim().toLowerCase();
		if (expectedTheme) {
			expect(signature.theme.toLowerCase()).toContain(expectedTheme);
		}

		const expectedPlugins = (process.env.OGS_E2E_EXPECT_PLUGINS || '')
			.split(',')
			.map((item) => item.trim().toLowerCase())
			.filter(Boolean);
		if (expectedPlugins.length > 0) {
			for (const plugin of expectedPlugins) {
				expect(signature.activePlugins.some((name) => name.toLowerCase().includes(plugin))).toBeTruthy();
			}
		}
	});

	test('current environment signature keeps critical SEO admin surfaces reachable', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-schema&schema_tab=runtime`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { name: 'Runtime Inspector' })).toBeVisible();

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-search-appearance&ogs_show_advanced=1`, { waitUntil: 'domcontentloaded' });
		await expect(page.locator('text=Global Defaults')).toBeVisible();

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-masters-plus&ogs_show_advanced=1`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { name: 'SEO MASTERS PLUS' })).toBeVisible();
	});
});
