# Architecture

Namespace base: `OpenGrowthSolutions\\OpenGrowthSEO`

## Domains
- Core: bootstrap, activator, defaults
- Admin: operational dashboard (SEO/AEO/GEO), setup wizard with resumable draft state, settings pages, editor controls
- SEO: frontend meta, robots, sitemaps
- Schema: JSON-LD graph generation
- AEO: answer-first heuristics
- GEO: bot controls plus heuristics for text visibility, schema-text consistency, citability, semantic clarity, freshness, entity signals, and internal discoverability
- Integrations: IndexNow + optional connectors (Google Search Console, Bing Webmaster Tools, GA4 helper) managed through a decoupled integration manager
- Audit: scan engine and findings cache
- REST: secure routes
- CLI: `wp ogs-seo ...` (audit/status/incremental/ignore/unignore plus module diagnostics)
- Jobs/Background: queue scheduling
- Compatibility: SEO plugin conflict warning
- Support/Shared: autoload, settings, privacy, developer tools (diagnostics/export/import/reset/logs)

## Storage
- Main options: `ogs_seo_settings`
- Developer debug logs: `ogs_seo_debug_logs`
- Audit cache: `ogs_seo_audit_issues`, `ogs_seo_audit_last_run`
- Audit runtime state: `ogs_seo_audit_scan_state`, `ogs_seo_audit_cache`, `ogs_seo_audit_ignored`
- Queue: `ogs_seo_indexnow_queue`
- IndexNow runtime options: `ogs_seo_indexnow_status`, `ogs_seo_indexnow_last_sent`, `ogs_seo_indexnow_key_verified`, `ogs_seo_indexnow_failed`
- Post meta for per-content overrides

## Performance controls
- Conditional asset loading in admin/editor only
- Transient cache for sitemap files with versioned invalidation and paginated sitemap generation
- Hourly cron jobs for audit/queue processing
- Incremental audit batches with resumable offset state
- No heavy frontend queries
