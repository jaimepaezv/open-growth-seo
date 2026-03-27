const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

test.describe('Open Growth SEO Developer Tools', () => {
	test('saves developer settings and validates invalid JSON import safely', async ({ page }) => {
		test.setTimeout(60000);
		const toolsUrl = `${BASE_URL}/wp-admin/admin.php?page=ogs-seo-tools&ogs_show_advanced=1`;
		await page.goto(toolsUrl, { waitUntil: 'domcontentloaded' });
		if (page.url().includes('wp-login.php')) {
			await page.fill('#user_login', ADMIN_USER);
			await page.fill('#user_pass', ADMIN_PASS);
			await Promise.all([
				page.waitForURL(/wp-admin/, { timeout: 20000 }),
				page.click('#wp-submit')
			]);
			await page.goto(toolsUrl, { waitUntil: 'domcontentloaded' });
		}

		await expect(page.getByRole('heading', { level: 1, name: 'Tools' })).toBeVisible();
		const showAdvanced = page.getByRole('link', { name: /Show advanced section/i });
		if (await showAdvanced.count()) {
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				showAdvanced.first().click()
			]);
		}

		await expect(page.getByText('Support overview', { exact: true })).toBeVisible();
		await expect(page.getByText('Health checks', { exact: true })).toBeVisible();
		await expect(page.getByText('Raw diagnostics snapshot', { exact: true })).toBeVisible();
		await expect(page.getByText('Configuration Export and Import', { exact: true })).toBeVisible();
		await expect(page.getByRole('link', { name: /Testing and support docs/i })).toBeVisible();
		await expect(page.locator('select[name="ogs[diagnostic_mode]"]')).toBeVisible();
		await expect(page.locator('select[name="ogs[debug_logs_enabled]"]')).toBeVisible();
		await expect(page.locator('button:has-text("Save changes")')).toBeVisible();

		await page.selectOption('select[name="ogs[diagnostic_mode]"]', '1');
		await page.selectOption('select[name="ogs[debug_logs_enabled]"]', '1');
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.click('button:has-text("Save changes")')
		]);

		await expect(page.locator('.notice.notice-success, .updated')).toContainText(/Settings saved|saved/i);

		const invalidPayload = '{"settings":';
		await page.fill('textarea[name="ogs_dev_payload"]', invalidPayload);
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.click('button:has-text("Import Settings")')
		]);
		await expect(page.locator('.notice.notice-error, .error')).toContainText(/not valid json|invalid json/i);
	});
});
