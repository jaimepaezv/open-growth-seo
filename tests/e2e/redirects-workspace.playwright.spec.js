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

async function openEditorNonceContext(page) {
	await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
	await page.waitForSelector('body', { timeout: 30000 });
	const nonce = await page.evaluate(() => (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '');
	if (!nonce) {
		throw new Error('REST nonce unavailable in admin editor context.');
	}
}

async function createPageViaRest(page, payload) {
	return page.evaluate(async (data) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce) {
			throw new Error('REST nonce unavailable.');
		}
		const response = await fetch(`${window.location.origin}/wp-json/wp/v2/pages`, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce
			},
			body: JSON.stringify(data)
		});
		if (!response.ok) {
			throw new Error(`Page create failed (${response.status}).`);
		}
		return response.json();
	}, payload);
}

async function deletePageViaRest(page, postId) {
	return page.evaluate(async (id) => {
		const nonce = (window.wpApiSettings && window.wpApiSettings.nonce) ? window.wpApiSettings.nonce : '';
		if (!nonce || !id) {
			return false;
		}
		const response = await fetch(`${window.location.origin}/wp-json/wp/v2/pages/${Number(id)}?force=true`, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': nonce }
		});
		return response.ok;
	}, postId);
}

test.describe('Open Growth SEO Redirects workspace', () => {
	test('adds, toggles, and deletes a redirect rule from the admin workspace', async ({ page }) => {
		test.setTimeout(120000);
		await ensureLoggedIn(page);
		await openEditorNonceContext(page);

		let destinationId = 0;
		try {
			const unique = Date.now();
			const destination = await createPageViaRest(page, {
				title: `Redirect workspace destination ${unique}`,
				slug: `redirect-workspace-destination-${unique}`,
				content: 'Redirect workspace destination.',
				status: 'publish'
			});
			destinationId = Number(destination.id || 0);
			expect(destinationId).toBeGreaterThan(0);

			const sourcePath = `/redirect-workspace-${unique}/`;
			const sourceMatch = sourcePath.replace(/\/$/, '');
			await page.goto(`${BASE_URL}/wp-admin/admin.php?page=ogs-seo-redirects`, { waitUntil: 'domcontentloaded' });
			await expect(page.getByRole('heading', { level: 1, name: 'Redirects' })).toBeVisible();
			await expect(page.locator('#ogs_redirect_source_path')).toBeVisible();
			await expect(page.locator('.ogs-rest-action').first()).toBeVisible();

			await page.fill('#ogs_redirect_source_path', sourcePath);
			await page.fill('#ogs_redirect_destination_url', destination.link);
			await page.selectOption('#ogs_redirect_match_type', 'exact');
			await page.selectOption('#ogs_redirect_status_code', '301');
			await page.fill('#ogs_redirect_note', 'Playwright redirect coverage');
			await Promise.all([
				page.waitForURL(/page=ogs-seo-redirects/, { timeout: 20000 }),
				page.getByRole('button', { name: 'Add redirect rule' }).click()
			]);

			const rulesTable = page.locator('table.widefat').filter({ has: page.getByRole('columnheader', { name: 'Source' }) }).first();
			const row = rulesTable.locator('tbody tr').filter({ hasText: sourceMatch }).first();
			await expect(row).toBeVisible();
			await expect(row).toContainText(destination.link);
			await expect(row).toContainText('Playwright redirect coverage');
			await expect(row.getByRole('link', { name: 'Disable' })).toBeVisible();

			await Promise.all([
				page.waitForURL(/page=ogs-seo-redirects/, { timeout: 20000 }),
				row.getByRole('link', { name: 'Disable' }).click()
			]);

			const disabledRow = rulesTable.locator('tbody tr').filter({ hasText: sourceMatch }).first();
			await expect(disabledRow.getByRole('link', { name: 'Enable' })).toBeVisible();

			page.once('dialog', (dialog) => dialog.accept());
			await Promise.all([
				page.waitForURL(/page=ogs-seo-redirects/, { timeout: 20000 }),
				disabledRow.getByRole('link', { name: 'Delete' }).click()
			]);

			await expect(rulesTable.locator('tbody tr').filter({ hasText: sourceMatch })).toHaveCount(0);
		} finally {
			if (destinationId > 0) {
				await page.goto(`${BASE_URL}/wp-admin/post-new.php?post_type=page`, { waitUntil: 'domcontentloaded' });
				await deletePageViaRest(page, destinationId);
			}
		}
	});
});
