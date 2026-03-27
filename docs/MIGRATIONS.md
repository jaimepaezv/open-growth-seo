# Migrations

Versioned option schema is controlled in `Core\\Defaults` and applied on activation.

## 1.0.0
- Initial options baseline in `ogs_seo_settings`.
- No custom DB tables.

Future upgrades:
- Add `ogs_seo_db_version` option.
- Add idempotent migration class per version.

## Unreleased (post-1.0.0)
- Added optional integration settings in `ogs_seo_settings` (`gsc_*`, `bing_*`, `ga4_*`, integration timeout/retry/log controls).
- Added separate options for integration runtime state and secrets:
  - `ogs_seo_integration_state`
  - `ogs_seo_integration_secrets`
  - `ogs_seo_integration_logs`
- Added IndexNow runtime options:
  - `ogs_seo_indexnow_status`
  - `ogs_seo_indexnow_last_sent`
  - `ogs_seo_indexnow_key_verified`
  - `ogs_seo_indexnow_failed`
- Added audit runtime options:
  - `ogs_seo_audit_scan_state`
  - `ogs_seo_audit_cache`
  - `ogs_seo_audit_ignored`
- Added compatibility/import runtime options:
  - `ogs_seo_import_state`
  - `ogs_seo_import_rollback`
- Added developer runtime options:
  - `ogs_seo_debug_logs`
- Added developer settings in `ogs_seo_settings`:
  - `diagnostic_mode`
  - `debug_logs_enabled`
