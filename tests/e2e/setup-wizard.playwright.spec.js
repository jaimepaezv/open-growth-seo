const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

test.describe('Open Growth SEO Setup Wizard UX', () => {
	test('preserves safe step flow and validates private visibility confirmation', async ({ page }) => {
		test.setTimeout(60000);
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

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-setup`, { waitUntil: 'domcontentloaded' });
		await expect(page.getByRole('heading', { level: 1, name: 'Setup Wizard' })).toBeVisible();
		const currentStep = page.locator('.ogs-wizard-progress li[aria-current="step"]');
		await expect(currentStep).toBeVisible();
		const currentStepText = (await currentStep.textContent()) || '';
		if (!currentStepText.includes('Step 1')) {
			page.once('dialog', (dialog) => dialog.accept());
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				page.click('button[name="ogs_wizard_action"][value="restart"]')
			]);
		}
		await expect(page.locator('.ogs-wizard-progress li[aria-current="step"]')).toContainText('Step 1');

		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.click('button[name="ogs_wizard_action"][value="next"]')
		]);
		await expect(page.locator('.ogs-wizard-progress li[aria-current="step"]')).toContainText('Step 2');

		const privateConfirmRow = page.locator('#ogs-private-confirm');
		await expect(privateConfirmRow).toBeHidden();
		await page.check('input[name="ogs_wizard[visibility]"][value="private"]');
		await expect(privateConfirmRow).toBeVisible();

		await page.click('button[name="ogs_wizard_action"][value="next"]');
		await expect(page.locator('.ogs-wizard-inline-error')).toContainText(/Confirm indexing warning/i);
		await expect(page.locator('input[name="ogs_wizard[confirm_private]"]')).toBeFocused();

		await page.check('input[name="ogs_wizard[confirm_private]"]');
		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.click('button[name="ogs_wizard_action"][value="next"]')
		]);
		await expect(page.locator('.ogs-wizard-progress li[aria-current="step"]')).toContainText('Step 3');
		await expect(page.locator('button[name="ogs_wizard_action"][value="apply"]')).toBeVisible();
	});
});
