const { test, expect } = require('@playwright/test');

const BASE_URL = process.env.OGS_E2E_BASE_URL || 'http://localhost:8888';
const ADMIN_USER = process.env.OGS_E2E_ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.OGS_E2E_ADMIN_PASS || 'password';
const REQUIRE_CLASSIC = process.env.OGS_E2E_REQUIRE_CLASSIC === '1';

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

async function requireClassicRuntime(page, editorUrl) {
	await page.goto(editorUrl, { waitUntil: 'domcontentloaded' });
	const hasTitleField = (await page.locator('#title').count()) > 0;
	const hasSeoField = (await page.locator('input[name="ogs_seo_title"]').count()) > 0;
	if (hasTitleField && hasSeoField) {
		return;
	}

	const reason = 'Classic editor runtime not provisioned in this environment.';
	if (REQUIRE_CLASSIC) {
		throw new Error(`${reason} Run tests/runtime/setup-classic-editor.ps1 before executing Classic coverage.`);
	}
	test.skip(true, `${reason} Set OGS_E2E_REQUIRE_CLASSIC=1 to hard-fail when Classic provisioning is missing.`);
}

test.describe('Open Growth SEO Classic Editor Runtime', () => {
	test('Classic metabox renders and keeps SEO values after save/reload', async ({ page }) => {
		test.setTimeout(90000);
		await ensureLoggedIn(page);
		await requireClassicRuntime(page, `${BASE_URL}/wp-admin/post-new.php?post_type=page&classic-editor`);

		const unique = Date.now();
		const seoTitle = `Classic SEO Title ${unique}`;
		const canonical = `${BASE_URL}/classic-canonical-${unique}/`;
		const socialImage = `${BASE_URL}/wp-content/uploads/classic-social-${unique}.jpg`;

		await expect(page.locator('#title')).toBeVisible();
		await expect(page.locator('input[name="ogs_seo_title"]')).toBeVisible();
		await expect(page.locator('input[name="ogs_seo_canonical"]')).toBeVisible();
		await expect(page.locator('input[name="ogs_seo_social_image"]')).toBeVisible();

		await page.locator('#title').fill(`Classic deterministic ${unique}`);
		await page.locator('input[name="ogs_seo_title"]').fill(seoTitle);
		await page.locator('input[name="ogs_seo_canonical"]').fill(canonical);
		await page.locator('input[name="ogs_seo_social_image"]').fill(socialImage);
		await page.locator('select[name="ogs_seo_index"]').selectOption('index');
		await page.locator('select[name="ogs_seo_follow"]').selectOption('follow');

		await Promise.all([
			page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
			page.locator('#publish').click()
		]);

		const currentUrl = new URL(page.url());
		const postId = Number(currentUrl.searchParams.get('post') || '0');
		expect(postId).toBeGreaterThan(0);

		await page.reload({ waitUntil: 'domcontentloaded' });
		await expect(page.locator('input[name="ogs_seo_title"]')).toHaveValue(seoTitle);
		await expect(page.locator('input[name="ogs_seo_canonical"]')).toHaveValue(canonical);
		await expect(page.locator('input[name="ogs_seo_social_image"]')).toHaveValue(socialImage);

		const trashLink = page.locator('#delete-action a.submitdelete');
		if (await trashLink.count()) {
			await Promise.all([
				page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
				trashLink.click()
			]);
		}
	});
});
