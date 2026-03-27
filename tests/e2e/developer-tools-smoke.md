# Developer Tools Smoke

1. Open `Open Growth SEO > Tools`.
2. Enable `Diagnostic mode` and `Developer debug logs`; save.
3. Verify diagnostics table renders current runtime values.
4. Run settings export and validate downloaded JSON.
5. Edit exported JSON minimally and import with merge enabled.
6. Confirm import success notice and changed-key count.
7. Import invalid JSON and confirm validation error.
8. Trigger reset with confirmation and verify defaults restored.
9. Validate debug logs table displays entries without secrets.
10. Clear logs and verify empty state.
11. Validate REST endpoints:
   - `GET /wp-json/ogs-seo/v1/dev-tools/diagnostics`
   - `GET /wp-json/ogs-seo/v1/dev-tools/export`
   - `POST /wp-json/ogs-seo/v1/dev-tools/import`
   - `POST /wp-json/ogs-seo/v1/dev-tools/reset`
   - `GET /wp-json/ogs-seo/v1/dev-tools/logs`
   - `POST /wp-json/ogs-seo/v1/dev-tools/logs/clear`
12. Validate WP-CLI equivalents:
   - `wp ogs-seo tools diagnostics`
   - `wp ogs-seo tools export --path=...`
   - `wp ogs-seo tools import --path=... --merge=1`
   - `wp ogs-seo tools reset --yes`
   - `wp ogs-seo tools logs --limit=10`
13. Run Playwright smoke: `npx playwright test tests/e2e/developer-tools.playwright.spec.js --reporter=line`.
