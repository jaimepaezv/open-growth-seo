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

test.describe('Open Growth SEO Jobs recovery actions', () => {
	test('integrations admin exposes queue recovery actions with clear feedback', async ({ page }) => {
		test.setTimeout(60000);
		await ensureLoggedIn(page);
		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-integrations`, { waitUntil: 'domcontentloaded' });

		await expect(page.getByRole('heading', { level: 1, name: 'Integrations' })).toBeVisible();
		await expect(page.getByRole('link', { name: 'Process Queue Now' })).toBeVisible();
		await expect(page.getByRole('link', { name: 'Requeue Failed URLs' })).toBeVisible();
		await expect(page.getByRole('link', { name: 'Clear Failed History' })).toBeVisible();

		await Promise.all([
			page.waitForLoadState('domcontentloaded'),
			page.getByRole('link', { name: 'Clear Failed History' }).click()
		]);
		await expect(page.locator('#setting-error-indexnow_clear_failed')).toContainText(/failed queue history cleared/i);

		await Promise.all([
			page.waitForLoadState('domcontentloaded'),
			page.getByRole('link', { name: 'Requeue Failed URLs' }).click()
		]);
		await expect(page.locator('#setting-error-indexnow_requeue_failed')).toContainText(/No failed IndexNow URLs were available to requeue|Requeued \d+ failed IndexNow URLs/i);
	});
});
