# Integrations Module QA Smoke

1. Open `Open Growth SEO > Integrations` and verify status table shows Google Search Console, Bing Webmaster Tools, and GA4 helper.
2. Verify each integration can be enabled/disabled without affecting plugin core screens/output.
3. Save credentials in secret fields and confirm they are not re-rendered in clear text on reload.
4. Click `Test connection` for each integration:
   - with missing credentials -> clear error state
   - with placeholder credentials -> handled failure state (no fatal errors)
5. Click `Disconnect` and verify integration is disabled and credentials are removed.
6. Verify IndexNow settings remain independent and functional.
7. Verify `GET /wp-json/ogs-seo/v1/integrations/status` returns protected status payload for admins only.
8. Verify `POST /wp-json/ogs-seo/v1/integrations/test` enforces permissions and returns structured success/error messages.
9. Run `wp ogs-seo integrations status`, `wp ogs-seo integrations test --integration=google_search_console`, and `wp ogs-seo integrations disconnect --integration=google_search_console`.
10. Confirm plugin frontend SEO output remains stable when all integrations are disabled or failing.
