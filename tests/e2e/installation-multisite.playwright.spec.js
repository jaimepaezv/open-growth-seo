const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

async function loginIfNeeded(page) {
	await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	const loginInput = page.locator('#user_login');
	if ((await loginInput.count()) === 0) {
		return;
	}
	await page.fill('#user_login', ADMIN_USER);
	await page.fill('#user_pass', ADMIN_PASS);
	await Promise.all([
		page.waitForURL(/wp-admin/, { timeout: 20000 }),
		page.click('#wp-submit'),
	]);
}

async function ensureMultisite(page) {
	await page.goto(`${BASE_URL}/wp-admin/`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('body', { timeout: 20000 });
	return (await page.locator('#wp-admin-bar-my-sites').count()) > 0;
}

test.describe('Open Growth SEO installation multisite', () => {
	test('keeps installation entry points reachable on multisite admin surfaces', async ({ page }) => {
		test.setTimeout(120000);
		await loginIfNeeded(page);
		const multisite = await ensureMultisite(page);
		test.skip(!multisite, 'Multisite environment is not provisioned for this installation E2E run.');

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-dashboard`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { level: 1, name: 'Open Growth SEO Dashboard' })).toBeVisible();
		await expect(page.locator('#wp-admin-bar-my-sites')).toBeVisible();

		const setupNotice = page.locator('.ogs-installation-notice');
		if ((await setupNotice.count()) > 0) {
			await expect(setupNotice.first()).toBeVisible();
		}

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-tools&ogs_show_advanced=1`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { level: 2, name: 'Installation Profile' })).toBeVisible();
		await expect(page.getByText('Recorded rebuilds')).toBeVisible();
		await expect(page.getByText('Recorded repair runs')).toBeVisible();

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-setup`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { level: 1, name: 'Setup Wizard' })).toBeVisible();
		await expect(page.locator('#wp-admin-bar-my-sites')).toBeVisible();
	});
});
