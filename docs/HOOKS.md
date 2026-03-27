# Hooks

## Actions
- `ogs_seo_indexnow_process`: Process IndexNow queue batch.
- `ogs_seo_run_audit`: Trigger audit scan run.
- `admin_post_ogs_seo_integration_test`: Test an integration connection from wp-admin.
- `admin_post_ogs_seo_integration_disconnect`: Disconnect an integration and clear stored secrets.
- `admin_post_ogs_seo_indexnow_generate_key`: Generate IndexNow key from wp-admin.
- `admin_post_ogs_seo_indexnow_verify_key`: Verify IndexNow key accessibility from wp-admin.
- `admin_post_ogs_seo_indexnow_process_now`: Trigger immediate IndexNow queue processing from wp-admin.
- `admin_post_ogs_seo_ignore_issue`: Ignore an audit issue with required reason.
- `admin_post_ogs_seo_unignore_issue`: Restore ignored audit issue.
- `admin_post_ogs_seo_import_dry_run`: Execute importer dry-run summary from Tools screen.
- `admin_post_ogs_seo_import_run`: Execute importer migration for selected providers.
- `admin_post_ogs_seo_import_rollback`: Restore last migration snapshot.
- `admin_post_ogs_seo_dev_export`: Export plugin settings as JSON.
- `admin_post_ogs_seo_dev_import`: Import plugin settings from JSON payload.
- `admin_post_ogs_seo_dev_reset`: Reset plugin settings to defaults.
- `admin_post_ogs_seo_dev_clear_logs`: Clear developer debug logs.
- `ogs_seo_dev_tools_after_import`: Fired after developer-tools settings import.
- `ogs_seo_dev_tools_after_reset`: Fired after developer-tools settings reset.

## Filters
- `ogs_seo_audit_checks`: Register custom audit callbacks.
- `ogs_seo_indexnow_verification_urls`: Filter candidate URLs used to verify IndexNow key accessibility.
- `ogs_seo_dev_tools_diagnostics`: Filter developer diagnostics payload.
- `ogs_seo_dev_tools_export_payload`: Filter settings export payload before serialization.
- `ogs_seo_dev_tools_import_payload`: Filter parsed import payload before sanitize/update.

Callback contract for `ogs_seo_audit_checks`:
- Return one issue array or an array of issue arrays.
- Supported issue keys:
  - `severity`: `critical|important|minor`
  - `title`: issue title
  - `explanation`: concise description of the problem
  - `recommendation`: actionable fix
  - `trace`: associative array with traceable references (`post_id`, `url`, `setting`, etc.)
  - `source`: module identifier (`core`, `schema`, `content`, etc.)
  - `id`: optional stable custom id; if omitted, plugin generates one
