const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';

test.describe('Open Growth SEO Search Appearance', () => {
	test('updates snippet preview from real form inputs', async ({ page }) => {
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

		await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-search-appearance`, { waitUntil: 'domcontentloaded' });

		await expect(page.getByRole('heading', { level: 1, name: 'Search Appearance' })).toBeVisible();
		await expect(page.getByRole('heading', { level: 2, name: 'Workflow continuity' })).toBeVisible();
		await expect(page.getByText('Validate output quality', { exact: true })).toBeVisible();
		await expect(page.locator('#ogs-snippet-preview')).toBeVisible();

		await page.fill('input[name="ogs[title_separator]"]', '-');
		await page.fill('input[name="ogs[title_template]"]', '%%title%% %%sep%% %%sitename%%');
		await page.fill('input[name="ogs[meta_description_template]"]', 'Answer-first: %%excerpt%%');

		const previewTitle = page.locator('#ogs-snippet-preview .ogs-snippet-title');
		const previewDesc = page.locator('#ogs-snippet-preview .ogs-snippet-desc');
		await expect(previewTitle).toContainText(/Example Service Page\s*-\s*/);
		await expect(previewDesc).toContainText('Answer-first:');

		await page.selectOption('select[name="ogs[og_enabled]"]', '0');
		await page.selectOption('select[name="ogs[twitter_enabled]"]', '0');
		await expect(page.locator('#ogs-snippet-preview [data-role="og-enabled"]')).toContainText('Disabled');
		await expect(page.locator('#ogs-snippet-preview [data-role="twitter-enabled"]')).toContainText('Disabled');

		await expect(page.locator('#ogs-snippet-preview [data-role="serp-title-count"]')).toContainText(/\d+/);
		await expect(page.locator('#ogs-snippet-preview [data-role="serp-desc-count"]')).toContainText(/\d+/);
		await expect(page.locator('#ogs-snippet-preview [data-role="preview-status"]')).toContainText(/Preview updated|fallback/i);
		await expect(page.locator('#ogs-snippet-preview [data-role="rich-hints"] li').first()).toBeVisible();
	});
});
