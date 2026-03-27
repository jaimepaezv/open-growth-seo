# Security

- Input is sanitized via WordPress sanitizers.
- Admin forms use capability checks + nonces.
- REST routes include `permission_callback`.
- Output escaped by context.
- No sensitive credentials are logged.
- Integration secrets are stored separately from general settings and are never rendered back in plain text.
- Integration logs are redacted, bounded (rotation), and can be disabled from settings.
- Developer debug logs are redacted, bounded, and only generated when Diagnostic mode or Debug logs is enabled.
- Developer tools export/import/reset/log endpoints are restricted to `manage_options`.
- Settings export excludes credential secrets by design (secrets are stored outside `ogs_seo_settings`).
- Privacy integration registers exporter/eraser callbacks and privacy-policy guidance text for admin review.
- Uninstall supports data retention toggle.
- Cron queue/audit jobs are idempotent and bounded.
