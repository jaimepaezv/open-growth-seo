# Open Growth SEO — Agent Instructions

You are working on a professional WordPress plugin named **Open Growth SEO** by **Open Growth Solutions**.

## Product identity
- Plugin name: Open Growth SEO
- Plugin slug: open-growth-seo
- Text domain: open-growth-seo
- PHP namespace base: OpenGrowthSolutions\OpenGrowthSEO
- Global function prefix: ogs_
- Company: Open Growth Solutions
- Company URL: http://opengrowthsolutions.com

## Mission
Build, audit, complete, and harden this WordPress plugin so it is:
- production-ready
- secure
- highly usable
- technically correct
- performant
- maintainable
- well-tested
- aligned with current WordPress plugin best practices
- aligned with real SEO, AEO, and GEO best practices

## Non-negotiable rules
1. Never assume a feature is complete without inspecting the real code.
2. Never say a module works unless you have verified it by running relevant tests, checks, or executable validation steps.
3. If a module is incomplete, implement the missing parts completely.
4. After implementation, run tests again and fix failures.
5. Do not leave TODOs, stubs, placeholders, or fake implementations.
6. Avoid unnecessary rewrites. Prefer targeted, safe changes.
7. Use WordPress native APIs whenever reasonable.
8. Sanitize all input and escape all output in the correct context.
9. Use nonces and capability checks for sensitive actions.
10. Every REST route must have an appropriate permission_callback.
11. Avoid duplicate SEO output, duplicate schema, duplicate canonicals, duplicate robots tags, and duplicate social metadata.
12. Keep assets loaded only where needed.
13. Do not add hype features, speculative “AI SEO” gimmicks, or unnecessary settings.
14. Do not implement misleading GEO/AEO features. Focus on crawlability, indexability, structured data, snippet controls, bot controls, answer clarity, discoverability, and entity clarity.
15. Do not rely on deprecated Google sitemap ping behavior.
16. Do not treat llms.txt as a core requirement.
17. FAQ and HowTo rich result strategy must not be overclaimed.
18. Maintain compatibility with:
   - modern WordPress
   - Gutenberg
   - Classic Editor
   - single site
   - multisite
   - WooCommerce when active
   - no WooCommerce when inactive
19. If another SEO plugin is active, detect conflict risks and avoid duplicate output where feasible.

## Required working method
For any task:
1. Inspect relevant files first.
2. Identify architecture, data flow, settings storage, rendering path, editor integration, hooks, REST endpoints, assets, tests, and conflicts.
3. Produce a brief execution plan.
4. Implement only after understanding the current codebase.
5. Run targeted tests/checks.
6. If failures occur, fix them and rerun.
7. Summarize exactly what changed and what remains.

## Expected output format in each task
Use this exact reporting structure:

A. Initial state  
B. Relevant files and components  
C. Problems found  
D. Implementation plan  
E. Changes made  
F. Files modified/created  
G. Tests/checks run  
H. Real results  
I. Remaining limitations, if any

## WordPress engineering requirements
- Follow WordPress Coding Standards
- Use Settings API where appropriate
- Use post meta / term meta / options / site options appropriately
- Use custom DB tables only when clearly justified
- Avoid expensive queries in normal requests
- Use background processing carefully
- Avoid duplicate cron scheduling
- Flush rewrite rules only when truly needed
- Provide uninstall cleanup
- Ensure i18n/l10n
- Prefer wp-admin-consistent UX
- Respect accessibility and keyboard navigation
- Keep admin screens fast and readable

## SEO / AEO / GEO product principles
The plugin should implement and verify:
- global SEO search appearance
- per-content SEO controls
- robots.txt management
- meta robots and X-Robots-Tag controls
- canonicalization
- XML sitemaps
- hreflang when applicable
- schema / structured data engine
- AEO analysis grounded in answer extraction usefulness
- GEO controls grounded in bot management, snippet controls, semantic clarity, and content citability
- optional integrations such as Search Console, Bing Webmaster Tools, and IndexNow
- technical audit engine
- developer tools
- WooCommerce SEO support
- migration/import and compatibility layer

## Safety against false completion
A feature is NOT complete if:
- there is UI without backend logic
- there is saved data without frontend output
- there is output without validation
- there are settings without effective runtime behavior
- there are endpoints without permission checks
- there are checks without actionable remediation
- there are tests missing for critical paths that were changed

## Testing expectations
Whenever relevant, run:
- linting
- PHPCS / WPCS
- PHP unit/integration tests
- JS tests if present
- build checks if needed
- smoke checks for activation, saving, rendering, and editor integration

Do not claim a test passed if it was not actually executed.

## Definition of done
A module is only done when:
- code is implemented
- behavior is wired end-to-end
- conflicts are handled
- tests/checks are run
- results are reported honestly
- no obvious gaps remain