const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

test.describe('Open Growth SEO Dashboard', () => {
	test('loads cards and resolves live runtime checks', async ({ page }) => {
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
				page.waitForNavigation({ waitUntil: 'networkidle' }),
				page.click('#wp-submit')
			]);
		}

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-dashboard`, { waitUntil: 'domcontentloaded' });

		await expect(page.getByRole('heading', { level: 1, name: 'Open Growth SEO Dashboard' })).toBeVisible();
		await expect(page.locator('#ogs-overview-title')).toBeVisible();
		await expect(page.locator('#ogs-issues-title')).toBeVisible();
		await expect(page.locator('#ogs-actions-title')).toBeVisible();
		await expect(page.locator('#ogs-live-title')).toBeVisible();
		await expect(page.getByRole('heading', { level: 2, name: 'Workflow continuity' })).toBeVisible();
		await expect(page.getByRole('heading', { level: 2, name: 'Connected next steps' })).toBeVisible();
		await expect(page.getByText('Setup baseline', { exact: true })).toBeVisible();
		await expect(page.getByText('Operations and support', { exact: true })).toBeVisible();

		const liveRoot = page.locator('[data-ogs-live-status]');
		await expect(liveRoot).toBeVisible();
		const busyNow = await liveRoot.getAttribute('aria-busy');
		expect(['true', 'false']).toContain(busyNow);

		await expect(liveRoot).toHaveAttribute('aria-busy', 'false', { timeout: 20000 });
		await expect(liveRoot.locator('.ogs-live-check')).toHaveCount(3, { timeout: 20000 });
		await expect(liveRoot).toContainText('Sitemaps runtime');
		await expect(liveRoot).toContainText('Audit runtime');
		await expect(liveRoot).toContainText('Integrations runtime');
	});
});
