const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

test.describe('Open Growth SEO Webmaster Help', () => {
	test('opens inline verification help from Settings', async ({ page }) => {
		await page.goto(`${BASE_URL}/wp-login.php`, { waitUntil: 'domcontentloaded' });
		let requiresLogin = false;
		try {
			await page.waitForSelector('#user_login', { timeout: 2500 });
			requiresLogin = true;
		} catch (error) {
			requiresLogin = false;
		}

		if (requiresLogin) {
			await page.fill('#user_login', ADMIN_USER);
			await page.fill('#user_pass', ADMIN_PASS);
			await Promise.all([
				page.waitForURL(/wp-admin/, { timeout: 20000 }),
				page.click('#wp-submit')
			]);
		}

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-settings`, { waitUntil: 'domcontentloaded' });

		await expect(page.getByRole('heading', { level: 1, name: 'Settings' })).toBeVisible();
		await expect(page.getByRole('heading', { level: 2, name: 'Webmaster Verification' })).toBeVisible();
		await expect(page.getByRole('link', { name: /Open Google Search Console/i })).toBeVisible();

		await page.getByRole('button', { name: /How to get it/i }).first().click();

		await expect(page.locator('[data-ogs-help-modal]')).toBeVisible();
		await expect(page.getByRole('heading', { level: 2, name: /Google verification token/i })).toBeVisible();
		await expect(page.getByRole('link', { name: /Open official verification page/i })).toBeVisible();
		await expect(page.getByText(/Copy only the content value from the meta tag/i)).toBeVisible();

		await page.getByRole('button', { name: /Close help/i }).click();
		await expect(page.locator('[data-ogs-help-modal]')).toBeHidden();
	});
});
