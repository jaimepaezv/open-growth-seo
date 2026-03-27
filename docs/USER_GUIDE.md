# User Guide

## Setup Wizard
1. Open `Open Growth SEO > Setup Wizard`.
2. Review environment detection (site type, WooCommerce, languages, SEO plugins, indexability, robots.txt).
3. Choose `Simple` or `Advanced` mode and baseline visibility.
4. Review summary and apply.

Notes:
- Safe mode is enforced when another SEO plugin is active.
- You can pause, resume, restart, and re-open the wizard anytime.
- Applying configuration is only available on the final step; partial step submissions are blocked safely.
- Step 1 now distinguishes `robots.txt` source (physical vs virtual) and warns when a physical file is not writable.

## Dashboard
- Use dashboard for high-level SEO/AEO/GEO operational status.
- Run audits directly from dashboard.
- Review prioritized issues, recommendations, and recent activity.
- Overview cards are audit-aware: before first audit or with stale audits, cards show caution states instead of false “healthy”.
- Live Checks validates runtime endpoints for Sitemaps, Audit, and Integrations with explicit loading, success, empty, and error states.

## Search Appearance
- Configure global title/meta templates with safe variables.
- Override defaults per public post type (title, meta, robots).
- Configure taxonomy and archive robots defaults (author/date/search/attachments).
- Context-aware token resolution is automatic:
- `%%title%%` resolves to post title on singular views and to archive/search title on non-singular views.
- `%%excerpt%%` resolves to post excerpt/content summary on singular views and to taxonomy/author/site descriptions on archive contexts.
- Use live previews before saving:
- SERP web preview (title/url/meta) with character counters.
- Social card preview (title/description/image fallback) aligned with OG/Twitter settings.
- Rich result hints as eligibility guidance only (not guarantees).
- Global preview updates in real time using the admin-only REST resolver: `/wp-json/ogs-seo/v1/search-appearance/preview`.
- Per-content editor overrides always take precedence.

## Content Controls
- In post editor sidebar/metabox, override title, description, canonical, robots, and schema type.
- URL-level overrides are applied only on singular content requests.
- Gutenberg and Classic Editor show indexability context and live snippet preview.

## Sitemaps
- Enable XML sitemaps and choose included post types.
- Open sitemap index from the Sitemaps screen.
- Use CLI/REST status tools for diagnostics.

## Bots & Crawlers
- Configure GPTBot and OAI-SearchBot allow/disallow.
- Add custom robots rules carefully.

## Integrations
- Integrations are optional and can be enabled/disabled independently.
- Configure Google Search Console, Bing Webmaster Tools, and optional GA4 helper from `Open Growth SEO > Integrations`.
- Use `Test connection` to validate credentials and network/API accessibility safely.
- Use `Disconnect` to remove stored credentials and disable the integration.
- Core SEO behavior does not depend on external integrations.
- Enable IndexNow only when key/endpoint are configured.
- REST diagnostics: `/wp-json/ogs-seo/v1/integrations/status`, `/wp-json/ogs-seo/v1/integrations/test`, and `/wp-json/ogs-seo/v1/integrations/disconnect` (admin-only).
- IndexNow runtime endpoints (admin-only): `/wp-json/ogs-seo/v1/indexnow/status`, `/wp-json/ogs-seo/v1/indexnow/process`, `/wp-json/ogs-seo/v1/indexnow/verify-key`, `/wp-json/ogs-seo/v1/indexnow/generate-key`.
- WP-CLI diagnostics: `wp ogs-seo integrations status|test|disconnect`.
- WP-CLI IndexNow diagnostics: `wp ogs-seo indexnow status|process|verify-key|generate-key`.
- Integration logs are minimal, redacted, and rotatable; you can disable them in Integrations settings.
- IndexNow includes key generation/verification, queue processing controls, and status diagnostics.
- If `verify-key` is executed from WP-CLI in a containerized localhost setup, browser/admin verification is the source of truth when host networking blocks self-fetch.
- Integration secrets are stored separately from general settings and kept encrypted at rest when OpenSSL is available.

## Compatibility and Import
- Open `Open Growth SEO > Tools > Compatibility and Import` to detect active Yoast, Rank Math, and AIOSEO plugins.
- Keep `safe mode` enabled while coexistence is needed to minimize duplicate meta/schema output.
- Run `Dry Run` first to preview potential settings changes and importable source meta volume.
- Run `Import` only after reviewing dry-run output. Enable `Overwrite` only when you intentionally want source data to replace existing OGS values.
- Provider selection supports active and inactive supported providers, so you can import legacy metadata after deactivating a previous SEO plugin.
- Use `Rollback Last Import` to restore the most recent snapshot of imported settings and post meta.
- REST endpoints are available for automation: `/wp-json/ogs-seo/v1/compatibility/status|dry-run|import|rollback` (admin-only).
- WP-CLI commands are available for scripted migrations: `wp ogs-seo compatibility status|dry-run|import|rollback`.

## Developer Tools
- Open `Open Growth SEO > Tools` for diagnostics, export/import, reset, and developer debug logs.
- `Diagnostic mode` and `Developer debug logs` are off by default.
- Export/Import covers plugin settings only. Stored API secrets/credentials are intentionally excluded.
- Import payload size is capped for safety (admin and REST).
- Use `Reset Plugin Settings` for safe rollback to defaults; optionally preserve uninstall data-retention preference.
- REST developer endpoints (admin-only):
- `/wp-json/ogs-seo/v1/dev-tools/diagnostics`
- `/wp-json/ogs-seo/v1/dev-tools/export`
- `/wp-json/ogs-seo/v1/dev-tools/import`
- `/wp-json/ogs-seo/v1/dev-tools/reset`
- `/wp-json/ogs-seo/v1/dev-tools/logs`
- `/wp-json/ogs-seo/v1/dev-tools/logs/clear`
- WP-CLI developer endpoints:
- `wp ogs-seo tools diagnostics`
- `wp ogs-seo tools export [--path=...]`
- `wp ogs-seo tools import --path=... [--merge=1]`
- `wp ogs-seo tools reset --yes [--preserve_keep_data=1]`
- `wp ogs-seo tools logs [--limit=20|--clear=1]`

## Audits
- Review prioritized findings with severity, explanation, recommendation, source, and trace details.
- Use `Run Full Re-scan` for broader checks and rely on hourly incremental scans for low-overhead monitoring.
- Ignore noisy findings only with a reason, and restore them from the Ignored Issues list when needed.
- Use REST/CLI status endpoints for operational diagnostics in agency/dev workflows.

## Robots and Bots
- Use Visual mode for safe default management of robots.txt directives.
- Use Expert mode only for full manual rules with syntax validation.
- Configure GPTBot and OAI-SearchBot explicitly in Bots & Crawlers.
- Use bot simulation preview before saving changes.
- Validation warnings now highlight risky patterns such as `/wp-admin/` blocks without `admin-ajax` allowance and explicit full disallow for GPTBot/OAI-SearchBot.
- If validation fails, attempted robots settings remain visible in the form so you can fix and resubmit safely.
- Use Restore safe defaults to quickly recover from risky rules.
- Remember: robots.txt controls crawling, not deindexing.

## Meta Robots and Snippet Controls
- Configure global defaults in Search Appearance for nosnippet, max preview values, noarchive, notranslate, and unavailable_after.
- Override snippet controls per post type in Content Type Defaults.
- Override per URL in editor controls; precedence is URL > post type > global.
- Use data-nosnippet IDs to protect specific visible sections (by HTML id) from snippet extraction.
- Remember: noindex controls indexation; robots.txt controls crawling.

## Canonicalization
- Canonicals are generated contextually for singular, taxonomy, author, date, search, and archive pages.
- URL-level canonical override is supported per content item and sanitized to valid http/https absolute URLs.
- Query parameters are normalized to reduce duplicate canonical variants; search keeps only relevant query args.
- Audit checks flag invalid canonical overrides, unreachable internal targets, and noindex/canonical conflicts.

## XML Sitemaps
- Sitemaps are split by post type and paginated automatically for scale.
- URLs are included only when indexable: noindex, password-protected, and canonicalized-to-other-URL content are excluded.
- Sitemap index lists only non-empty child sitemaps based on the same inclusion rules, preventing stale/empty child entries.
- Child sitemap `lastmod` values are emitted per sitemap page from the newest URL in that page, and out-of-range child URLs return 404 + empty urlset.
- Use Sitemaps screen buttons to inspect index output and JSON diagnostics (status and inspect).
- Cache is invalidated automatically on relevant content/meta/settings changes and can be flushed via WP-CLI.

## Hreflang and International SEO
- Enable hreflang only when equivalent multilingual/multiregional URLs exist.
- Open Growth SEO auto-detects Polylang and WPML for alternates mapping when available.
- Configure x-default as Auto, Custom URL, or Disabled.
- Use manual map fallback (code|url) for controlled cases like homepage alternates when no provider integration is available.
- Check provider, detected languages, and sample alternates in Search Appearance and REST Hreflang Status.
- Runtime output is suppressed for singular URLs when canonical conflicts or provider reciprocity failures are detected.
- Manual-map `x-default` is deduplicated so it is never emitted as both locale alternate and x-default.

## Structured Data (Schema)
- Configure baseline graph (Organization, LocalBusiness, WebSite, WebPage, BreadcrumbList) in the Schema screen.
- Enable contextual content schemas only where content supports them (FAQ, QA, Product, Event, JobPosting, Recipe, SoftwareApplication, Dataset, etc.).
- Use per-URL schema type override in editor only when content truly matches the selected type.
- Use Schema Debug and REST Schema Inspect to review emitted graph, errors, and warnings before deployment.
- If WooCommerce is active, choose `Product schema source`:
- `WooCommerce native` to avoid duplicate Product markup (recommended default).
- `Open Growth SEO takeover` when you intentionally want OGS Product/Offer generation.
- In takeover mode, Product schema uses WooCommerce data for price, availability, SKU, variation-level offers, and review/aggregate rating when available.
- Return/shipping policy references are attached to Offer data when Woo policy pages are configured.
- Woo audit checks flag missing product price, variable products without priced variations, and thin product descriptions.

## AEO (Answer Engine Optimization)
- Use Content Controls page and editor analysis panels to identify answer-first, structure, intent coverage, and follow-up gaps.
- Recommendations are heuristic, actionable, and content-structure focused (not rank promises).
- Use REST (/ogs-seo/v1/aeo/analyze) or CLI (wp ogs-seo aeo analyze) for QA workflows.

## GEO (Generative Engine Optimization)
- Use Bots & Crawlers > GEO Diagnostics to review crawl controls, text visibility, schema-text consistency, citability, semantic clarity, freshness, internal discoverability, and snippet participation constraints.
- GEO diagnostics are technical heuristics for editorial and technical improvements, not guarantees of inclusion in generative systems.
- Use REST (/ogs-seo/v1/geo/analyze) or CLI (wp ogs-seo geo analyze) to inspect per-URL GEO signals during QA.
