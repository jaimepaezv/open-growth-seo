# Release Readiness

## Pre-release gates
1. Plugin activation/deactivation completes without fatal errors.
2. No avoidable notices/warnings with `WP_DEBUG` on key flows (dashboard, settings save, editor save, frontend head output).
3. REST endpoints require `permission_callback` and enforce role-capability boundaries.
4. Admin-post destructive actions require valid nonce and `manage_options`.
5. Frontend output is free of duplicate meta/schema in safe coexistence scenarios.
6. Sitemap, robots, canonical, and meta-robots outputs are mutually coherent.
7. Developer tools export/import/reset/logs operate safely and do not expose secrets.
8. Uninstall respects `keep_data_on_uninstall` and removes runtime options when disabled.
9. PHP/JS/CSS lint and smoke test checklists are executed in CI/local release pipeline.
10. Documentation set is updated (`USER_GUIDE`, `TESTING`, `SECURITY`, `HOOKS`, `MIGRATIONS`, `CHANGELOG`).

## Mandatory manual verification
1. Run all checklists under `tests/e2e/` relevant to changed modules.
2. Verify keyboard focus visibility across dashboard and tools forms.
3. Verify color contrast of status labels and notices in wp-admin default theme.
4. Validate translations load with `open-growth-seo` text domain.

## Known environment requirement
- QA automation requires local `php`, `composer`, `phpunit`, and `node` binaries.
