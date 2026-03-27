# Testing

## QA commands
- `composer install`
- `composer lint:php`
- `composer test:php`
- `composer qa`
- `npm run lint:js`
- `npm run lint:css`
- `npm run test:e2e`
- `npm run test:e2e:dashboard`
- `npm run test:e2e:search-appearance`
- `npm run test:e2e:developer-tools`
- `npm run test:e2e:setup-wizard`
- `npm run test:runtime`
- `npm run test:runtime:cli`
- `powershell -ExecutionPolicy Bypass -File tests/runtime/runtime-check.ps1`
- Runtime credentials can be overridden:  
  `powershell -ExecutionPolicy Bypass -File tests/runtime/runtime-check.ps1 -BaseUrl "http://localhost:8888" -AdminUser "admin" -AdminPass "<password>"`
- Runtime CLI can be run independently:  
  `powershell -ExecutionPolicy Bypass -File tests/runtime/runtime-check.ps1 -CliOnly`
- Local WP-CLI mode can be forced by pointing at a WordPress root:  
  `powershell -ExecutionPolicy Bypass -File tests/runtime/runtime-check.ps1 -CliOnly -CliMode local -WpPath "C:\path\to\wordpress"`
- If `-WpPath` is omitted, runtime CLI smoke auto-detects Docker Compose in `tests/runtime/docker-compose.yml` and falls back to local `wp-cli.phar` only when a valid WordPress root is provided.
- First-time Playwright setup: `npx playwright install chromium`

Dashboard Playwright smoke defaults:
- Base URL: `http://localhost:8888`
- Admin user/password: `admin` / `password`
- Override via env vars: `OGS_E2E_BASE_URL`, `OGS_E2E_ADMIN_USER`, `OGS_E2E_ADMIN_PASS`

## Manual smoke checks
1. Activate plugin without warnings.
2. Open each admin page under Open Growth SEO.
3. Edit post in Gutenberg and Classic; confirm fields save.
4. Verify meta tags and schema in frontend source.
5. Load `/ogs-sitemap.xml` and one subtype sitemap.
6. Check `/robots.txt` for managed rules.
7. Run `wp ogs-seo audit run`.
8. Trigger REST POST /wp-json/ogs-seo/v1/audit/run as admin.
9. Validate GET /wp-json/ogs-seo/v1/sitemaps/status as admin.
10. Run wp ogs-seo sitemap status and wp ogs-seo sitemap flush.

11. Open dashboard and verify issue prioritization, recommendations, and quick actions.
12. Trigger Run Audit Now from dashboard and confirm nonce-protected success flow.
13. Confirm dashboard live status widget resolves loading/success/empty/error states across sitemap, audit, and integrations runtime checks.

14. Run Setup Wizard end-to-end: detect, configure, summary, apply, and reopen.
15. Validate wizard pause/resume/restart flow using ogs_seo_wizard_draft state.

16. Validate Search Appearance templates render in frontend titles and descriptions.
16.1. Validate Search Appearance live preview updates SERP title/meta and character counts while typing.
16.2. Validate social card preview reflects fallback behavior and OG/Twitter enabled states.
16.3. Validate rich-result hints remain non-promissory and tied to enabled schema defaults.
16.4. Run Playwright smoke: `npx playwright test tests/e2e/search-appearance.playwright.spec.js --reporter=line`.
17. Validate robots defaults by context and per-content overrides.
18. Validate safe-mode duplicate prevention with another SEO plugin active.

19. Validate Classic metabox saves per-URL SEO overrides securely with nonce/capability checks.
20. Validate Gutenberg sidebar writes identical post meta and updates frontend output.
21. Validate quick edit/bulk edit/autosave/revisions do not unintentionally overwrite SEO meta.

22. Validate robots managed/expert modes, syntax validation, and critical safeguard behavior.
23. Validate GPTBot and OAI-SearchBot simulation previews and restore-defaults workflow.
24. Validate warning behavior when physical robots.txt exists.
25. Validate global + post type + URL-level precedence for nosnippet/max-snippet/max-image-preview/max-video-preview/noarchive/notranslate/unavailable_after.
26. Validate X-Robots-Tag output and wp_robots meta output are consistent for indexability/snippet directives.
27. Validate data-nosnippet IDs add attributes only to targeted HTML elements in singular main content.
28. Validate canonical resolution for singular, taxonomy, author, date, search, and paginated archive contexts.
29. Validate canonical override sanitization (absolute/root-relative allowed; invalid schemes rejected).
30. Run audit and validate canonical issue detection for invalid, unreachable, and noindex-conflict overrides.
31. Validate sitemap exclusion logic for noindex, password-protected posts, and non-self canonical overrides.
32. Validate sitemap index and type XML remain valid under cache hits and misses.
33. Validate REST inspect endpoint (/ogs-seo/v1/sitemaps/inspect) and CLI sitemap status diagnostics.
34. Validate hreflang alternates for multilingual pages with Polylang/WPML, including valid code format and x-default behavior.
35. Validate hreflang reciprocity and canonical conflict audit findings via wp ogs-seo audit run.
36. Validate fallback behavior (no multilingual plugin): no invalid hreflang output unless valid manual map is configured.
37. Validate schema graph contextuality per content type (Article/Product/FAQ/QA/Event/Job/Recipe/Software/Dataset) and ensure required fields are present.
38. Validate schema deduplication and node validation (no empty/misleading nodes emitted).
39. Validate REST schema inspect (/ogs-seo/v1/schema/inspect) and CLI schema status diagnostics.
40. Validate AEO signals: answer-first detection, structure detection (lists/steps/tables), intent coverage, follow-up generation, and internal-link recommendations.
41. Validate REST AEO analysis endpoint (/ogs-seo/v1/aeo/analyze) and CLI command (wp ogs-seo aeo analyze).
42. Validate AEO recommendations are actionable and update when editor content structure changes.
43. Validate GEO diagnostics for crawl visibility, text visibility, citability, semantic clarity, entity signals, freshness, and internal discoverability.
44. Validate REST GEO analysis endpoint (/ogs-seo/v1/geo/analyze) and CLI command (wp ogs-seo geo analyze).
45. Validate schema-text mismatch warnings when schema override does not match visible content patterns.
46. Validate data-nosnippet usage warnings when exclusion scope becomes excessive for snippet discoverability.
47. Validate Integrations screen supports independent enable/disable for Google Search Console, Bing Webmaster, GA4 helper, and IndexNow.
48. Validate integration secrets are accepted via password fields, never re-rendered in clear text, and preserved when fields are left empty.
49. Validate Test connection/Disconnect actions for each integration (success, failure, and no-credential states).
50. Validate REST integrations endpoints (/ogs-seo/v1/integrations/status and /ogs-seo/v1/integrations/test) permissions and error handling.
51. Validate WP-CLI integrations commands (wp ogs-seo integrations status|test|disconnect).
52. Validate integration logs are redacted, bounded, and disabled when `Integration logs` setting is off.
53. Validate IndexNow key generation and key verification endpoint (`/<key>.txt`) behavior.
54. Validate IndexNow queue deduplication, retries/backoff, and rate limiting under repeated updates.
55. Validate IndexNow REST endpoints (`/ogs-seo/v1/indexnow/status`, `/ogs-seo/v1/indexnow/process`) and CLI commands (`wp ogs-seo indexnow ...`).
56. Validate Audits list includes severity, explanation, recommendation, source, and trace for each issue.
57. Validate ignore flow requires reason and persists to ignored list; validate restore flow.
58. Validate incremental scan state progression (`offset`, `last_batch`, `mode`) and full re-scan reset behavior.
59. Validate `GET /wp-json/ogs-seo/v1/audit/status` includes `issues`, `ignored`, and `state`.
60. Validate `POST /wp-json/ogs-seo/v1/audit/ignore` and `/audit/unignore` errors for invalid payloads.
61. Validate WooCommerce schema mode toggle (`native` vs `ogs`) and confirm no duplicate Product nodes in native mode.
62. Validate variable products emit Offer data per priced variation in OGS takeover mode.
63. Validate product/category/tag archive robots behavior follows WooCommerce-specific settings.
64. Validate WooCommerce deactivation causes no fatals/notices and no broken settings screens.
65. Validate compatibility detection identifies active Yoast/RankMath/AIOSEO in single-site and multisite network-active scenarios.
66. Validate safe mode warning appears only on Open Growth SEO screens and clearly reports duplicate-output risk when disabled.
67. Validate import dry-run (Tools and REST) reports selected providers, settings preview count, source meta rows, and warnings.
68. Validate import run updates only mapped keys, honors overwrite toggle, and stores rollback snapshot.
69. Validate rollback restores both settings and post meta snapshot; verify truncation warning for very large migrations.
70. Validate compatibility REST endpoints (`/ogs-seo/v1/compatibility/status`, `/dry-run`, `/import`, `/rollback`) with admin permissions only.
71. Validate WP-CLI compatibility commands (`wp ogs-seo compatibility status|dry-run|import|rollback`) for expected output and failure handling.
72. Validate developer-tools REST endpoints (`/ogs-seo/v1/dev-tools/diagnostics|export|import|reset|logs|logs/clear`) are admin-only.
73. Validate settings export payload is valid JSON and excludes integration credential secrets.
74. Validate import payload handling for valid JSON, invalid JSON, merge vs replace mode, and changed-key reporting.
75. Validate reset flow requires explicit confirmation and restores defaults safely.
76. Validate developer debug logs are redacted, bounded, and clearable from Tools and CLI.
77. Validate WP-CLI developer commands (`wp ogs-seo tools diagnostics|export|import|reset|logs`) in success/error flows.
