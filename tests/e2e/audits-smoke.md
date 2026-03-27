# Audits Module Smoke

1. Open `Open Growth SEO > Audits` and verify active issues table includes severity, explanation, recommendation, and trace.
2. Click `Run Full Re-scan` and confirm `ogs_seo_audit_last_run` changes.
3. Verify scan state is updated (`mode`, `offset`, `last_batch`) after full and incremental runs.
4. Ignore one issue with a non-empty reason and confirm it is removed from active list.
5. Restore ignored issue and confirm it returns to active list.
6. Validate REST endpoints as admin: `GET /wp-json/ogs-seo/v1/audit/status`, `POST /wp-json/ogs-seo/v1/audit/run`, `POST /wp-json/ogs-seo/v1/audit/ignore`, `POST /wp-json/ogs-seo/v1/audit/unignore`.
7. Validate REST endpoints as non-admin return permission errors.
8. Validate WP-CLI commands: `wp ogs-seo audit status`, `wp ogs-seo audit run`, `wp ogs-seo audit run-incremental`, `wp ogs-seo audit ignore`, `wp ogs-seo audit unignore`.
9. Confirm issues include actionable traceability to setting key, URL, or post ID.
10. Confirm audit run does not trigger duplicate meta/schema output; it only reports findings.
