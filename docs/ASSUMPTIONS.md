# Assumptions

- WordPress 6.5+ and PHP 8.0+.
- Default operation is single-site unless multisite enabled.
- No external credentials were provided; integrations are optional and off by default.
- External integration connectivity (GSC/Bing/GA4) is validated via explicit test actions and safe fallbacks; core SEO output never depends on remote API success.
- Hreflang automation depends on detected integrations (Polylang/WPML) and remains conservative when no mapping source is available.
- Importers for third-party SEO plugins are compatibility-ready and intentionally conservative in v1.
- Import rollback snapshots are intentionally capped (`MAX_ROLLBACK_ITEMS = 3000` post-meta writes) to avoid unbounded option growth on high-volume sites.
- Developer settings export/import intentionally excludes credential secrets; secrets remain in dedicated secure storage and must be re-provided separately when needed.
- No guaranteed rank or AI inclusion claims are made.

- Setup wizard uses per-site options in multisite and does not override network-wide settings.
- Wizard applies conservative defaults and enforces safe mode when another SEO plugin is detected.

- Search Appearance templates support a restricted, documented variable set and sanitize all template input before persistence.

- robots.txt is managed as virtual output by WordPress; if a physical robots.txt exists, the server may serve it first.
- data-nosnippet protection is implemented by targeting existing HTML id attributes in singular main content; arbitrary CSS selectors are intentionally not supported in v1 for safety and performance.
- Canonical normalization strips non-essential query parameters by default to reduce duplicate URL variants; search intent parameters are preserved where relevant.
- Sitemap inclusion policy prefers canonical self-URLs; if a URL is manually canonicalized to a different target, the source URL is excluded from XML sitemap.
- Hreflang alternates are emitted in HTML head (not sitemap extensions) in v1 for lower operational complexity and clearer debugging.
- Without multilingual plugin integration, hreflang fallback is intentionally conservative and primarily manual-map driven for safe contexts.
- Schema output is intentionally conservative: types are emitted only when required visible signals/fields are present to avoid invalid or misleading markup.
- Hreflang and schema debug endpoints are admin-protected REST tools intended for QA and operational inspection.
- AEO analysis is heuristic and deterministic, designed for actionable editorial improvements rather than predictive ranking guarantees.
- GEO analysis is heuristic and deterministic, centered on crawl controls, discoverability, citability, semantic clarity, and schema-text consistency rather than speculative ranking claims.
- Audit scans prioritize actionable findings over exhaustive crawling; full scans process larger batches and hourly scans process incremental batches to control resource usage on medium/large sites.
- WooCommerce SEO defaults prioritize safety: Product schema source defaults to `WooCommerce native` to reduce duplicate structured data risk unless explicit OGS takeover is enabled.
