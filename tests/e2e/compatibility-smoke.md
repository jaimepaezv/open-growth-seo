# Compatibility and Import Smoke

1. Activate Open Growth SEO with Yoast/Rank Math/AIOSEO active.
2. Open `Open Growth SEO > Tools`.
3. Confirm detected plugins table lists active providers and safe-mode warning state.
4. Run `Dry Run` and verify summary notice + `Last Dry Run` report updates.
5. Run `Import` with overwrite disabled and verify only empty OGS fields are populated.
6. Run `Import` with overwrite enabled and verify mapped fields are replaced.
7. Run `Rollback Last Import` and verify previous settings/meta values are restored.
8. Hit REST endpoints as admin:
   - `GET /wp-json/ogs-seo/v1/compatibility/status`
   - `POST /wp-json/ogs-seo/v1/compatibility/dry-run`
   - `POST /wp-json/ogs-seo/v1/compatibility/import`
   - `POST /wp-json/ogs-seo/v1/compatibility/rollback`
9. Validate WP-CLI equivalents:
   - `wp ogs-seo compatibility status`
   - `wp ogs-seo compatibility dry-run --providers=yoast`
   - `wp ogs-seo compatibility import --providers=yoast --overwrite=0`
   - `wp ogs-seo compatibility rollback`
10. Deactivate conflicting plugin and confirm conflict notice is no longer shown.
