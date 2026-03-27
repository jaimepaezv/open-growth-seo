const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

async function loginIfNeeded(page) {
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
			page.click('#wp-submit'),
		]);
	}
}

test.describe('Open Growth SEO Installation Recovery', () => {
	test('surfaces setup-incomplete state and supports installation rebuild recovery', async ({ page }) => {
		test.setTimeout(90000);
		await loginIfNeeded(page);

		const toolsUrl = `${BASE_URL}/wp-admin/admin.php?page=ogs-seo-tools&ogs_show_advanced=1`;
		await page.goto(toolsUrl, { waitUntil: 'domcontentloaded' });

		await expect(page.getByRole('heading', { level: 1, name: 'Tools' })).toBeVisible();
		await expect(page.getByRole('heading', { level: 2, name: 'Installation Profile' })).toBeVisible();

		page.once('dialog', (dialog) => dialog.accept());
		await page.getByRole('button', { name: 'Reset Plugin Settings' }).click();

		await expect(page.locator('.notice.notice-success, .updated')).toContainText(/reset to defaults/i, { timeout: 20000 });
		await expect(page.getByText('Setup is not fully completed yet.')).toBeVisible();
		const showAdvanced = page.getByRole('link', { name: /Show advanced section/i });
		if (await showAdvanced.count()) {
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				showAdvanced.first().click(),
			]);
		}
		await expect(page.getByRole('heading', { level: 2, name: 'Installation Profile' })).toBeVisible();
		const installationProfileTable = page.locator('table.widefat').first();
		await expect(installationProfileTable).toContainText('Setup review recommended');
		await expect(installationProfileTable).toContainText('Yes');

		page.once('dialog', (dialog) => dialog.accept());
		await page.locator('a[href*="action=ogs_seo_rebuild_installation_state"]').last().click();

		await expect(page.locator('.notice.notice-success, .updated')).toContainText(/installation state rebuilt successfully/i, { timeout: 20000 });

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-dashboard`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByText('Setup review recommended.')).toBeVisible();
		await expect(page.getByRole('link', { name: 'Open Setup Wizard' })).toBeVisible();

		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.getByRole('link', { name: 'Open Setup Wizard' }).click(),
		]);

		await expect(page.getByRole('heading', { level: 1, name: 'Setup Wizard' })).toBeVisible();
		const currentStep = page.locator('.ogs-wizard-progress li[aria-current="step"]');
		await expect(currentStep).toBeVisible();
		const currentStepText = (await currentStep.textContent()) || '';
		if (!currentStepText.includes('Step 1')) {
			page.once('dialog', (dialog) => dialog.accept());
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				page.getByRole('button', { name: 'Restart' }).click(),
			]);
		}
		await expect(page.getByText('This looks like an incomplete or first-time setup path.')).toBeVisible();
	});
});
