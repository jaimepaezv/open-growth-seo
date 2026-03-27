# Open Growth SEO

Open Growth SEO is a full-stack WordPress SEO plugin for technical SEO, structured data, search presentation, AI-era content diagnostics, crawler controls, and operator-grade tooling.

It is released as **free software** under the **GNU General Public License v3.0 or later** (`GPL-3.0-or-later`) in the Free Software Foundation sense: you can run it, study it, modify it, and redistribute it under the license terms.

## Positioning

Open Growth SEO is designed as a **complete free-software replacement** for the kinds of commercial WordPress stacks many sites currently assemble with tools such as:

- Yoast SEO Premium
- Rank Math Pro
- All in One SEO Pro
- WP Rocket
- Redirection
- schema add-on plugins
- crawler and robots helper plugins
- fragmented audit and snippet-preview plugins

The goal is not to imitate any one product screen-by-screen. The goal is to provide a coherent, production-ready, GPLv3-or-later alternative that covers the same operational surface in one integrated plugin:

- search appearance
- indexation controls
- robots and bot policies
- XML sitemaps
- redirects and 404 handling
- schema and JSON-LD
- content-level SEO controls
- audit workflows
- AEO, GEO, and SFO analysis
- integrations
- diagnostics and support
- REST and WP-CLI operations

If you want a **single, auditable, free-software plugin** instead of a layered stack of premium SEO plugins plus performance and redirect helpers, this plugin is built for that use case.

## What The Plugin Covers

### Search appearance and per-URL controls

- global title and meta description templates
- post type template defaults
- realistic search snippet preview
- social title, description, and image overrides
- canonical URL controls
- granular index/follow handling
- advanced preview directives:
  - `max-snippet`
  - `max-image-preview`
  - `max-video-preview`
  - `nosnippet`
  - `noarchive`
  - `notranslate`
  - `unavailable_after`
  - `data-nosnippet` targeting

### Robots and crawler control

- managed `robots.txt`
- safe robots mode validation
- global crawl policy controls
- explicit handling for:
  - `GPTBot`
  - `OAI-SearchBot`
- bot-aware GEO analysis and operator feedback

### XML sitemaps

- XML sitemap index
- post-type sitemap generation
- noindex-aware inclusion logic
- runtime status inspection
- cache versioning and flush controls

### Redirects and 404 management

- native redirects subsystem
- 404 event logging
- safe add, toggle, delete, and import workflows
- importer support for common SEO/redirect ecosystems
- dry-run import analysis
- rollback support

### Schema and JSON-LD

Open Growth SEO includes a broad schema engine with:

- rule eligibility
- validation
- conflict detection
- runtime inspection
- per-post override controls
- CPT defaults
- industry presets
- import/export of CPT schema mappings
- saved-state JSON-LD visual preview in the editor

Supported schema includes site-level, page-level, content, service, software, academic, glossary, and review-oriented types, including:

- `Organization`
- `CollegeOrUniversity`
- `WebSite`
- `WebPage`
- `AboutPage`
- `ContactPage`
- `CollectionPage`
- `ProfilePage`
- `BreadcrumbList`
- `Person`
- `Article`
- `BlogPosting`
- `NewsArticle`
- `TechArticle`
- `ScholarlyArticle`
- `FAQPage`
- `QAPage`
- `DiscussionForumPosting`
- `Guide`
- `DefinedTerm`
- `DefinedTermSet`
- `Course`
- `EducationalOccupationalProgram`
- `Event`
- `EventSeries`
- `JobPosting`
- `Dataset`
- `Product`
- `Review`
- `AggregateRating`
- `Service`
- `Offer`
- `OfferCatalog`
- `ServiceChannel`
- `ContactPoint`
- `SoftwareApplication`
- `WebAPI`
- `Project`
- `LocalBusiness`

Important implementation detail:

- the plugin does **not** blindly force every possible schema type onto every page
- eligibility, validation, and runtime guards decide when markup is appropriate
- manual overrides exist, but they are intentionally secondary to safer automatic behavior

### CPT-first schema workflows

The schema module is designed to work properly with custom post types.

It supports:

- auto-detection by content
- auto-detection by `post_type` slug and labels
- post-type default schema mapping
- per-post override when genuinely needed
- sector presets for fast setup
- mapping import/export between sites
- saved final JSON-LD preview for each post

This makes it suitable for sites with structured content models such as:

- university websites
- clinics and healthcare practices
- SaaS and developer docs sites
- agencies
- marketplaces
- publishers and media hubs
- nonprofits and associations

### Audits and diagnostics

The plugin includes a real audit engine with:

- full and incremental audit runs
- issue ignoring/unignoring
- safe-fix flows for selected issues
- severity, grouping, quick wins, and prioritization
- REST exposure
- admin reporting

Checks cover practical areas such as:

- titles
- descriptions
- indexability
- canonicals
- robots contradictions
- internal linking and orphan risk
- schema/runtime mismatches
- content thinness and structure
- image alt gaps
- WooCommerce archive risks

### AEO, GEO, and SFO

The plugin goes beyond legacy SEO controls.

It includes:

- **AEO**: answer-first and extractable-answer analysis
- **GEO**: crawler and generative-engine visibility analysis
- **SFO**: search feature opportunity analysis

These modules expose:

- per-post analysis
- priority actions
- scores and rollups
- trend tracking where implemented
- admin workspaces
- REST endpoints
- editor hints where appropriate

### Integrations

Current integration surface includes:

- Google Search Console
- Bing Webmaster Tools
- GA4 reporting support
- IndexNow

The plugin includes:

- credentials handling
- validation
- connection status
- operational reporting
- safer queue-backed processing where relevant

### Jobs, operations, and support

- hardened background job queue for deferred processes
- lock handling and stale-lock recovery
- job status and recovery actions
- support diagnostics
- Site Health integration
- developer tools for logs, export/import, and resets

### REST and WP-CLI

Open Growth SEO is not only a wp-admin plugin. It also includes:

- operational REST routes
- schema inspection routes
- content analysis routes
- jobs endpoints
- integrations endpoints
- tools endpoints
- WP-CLI commands for audits, sitemaps, schema, integrations, jobs, compatibility, and diagnostics

This makes it viable for:

- agencies
- DevOps workflows
- CI checks
- support and maintenance teams
- multi-environment operational work

## Why This Exists

Most WordPress SEO stacks drift into one of two bad outcomes:

1. a monolithic premium plugin that becomes hard to trust, hard to audit, and hard to extend
2. a pile of unrelated plugins for redirects, schema, sitemaps, robots, audits, and bot controls

Open Growth SEO takes a different approach:

- one plugin
- modular internals
- strong admin UX
- operational tooling
- WordPress-native workflows
- free software licensing

## Product Principles

- safe defaults first
- advanced control only where justified
- no decorative complexity
- operational clarity over marketing noise
- CPT compatibility as a first-class design concern
- REST, CLI, admin, and support surfaces should all agree
- schema should be semantically useful, not inflated
- diagnostics should be actionable, not generic

## Replacement Scope

For many sites, Open Growth SEO is intended to replace the operational role commonly handled by:

- Yoast SEO Premium
- Rank Math Pro
- AIOSEO Pro
- WP Rocket
- separate schema plugins
- separate redirect plugins
- separate robots/bot plugins
- separate SEO audit plugins

That replacement claim should be interpreted at the **product surface and workflow** level:

- it aims to cover the same practical jobs teams need to run
- it does so under GPLv3-or-later
- it keeps the stack inspectable and self-hosted

It does **not** claim byte-for-byte feature parity with every proprietary plugin or every proprietary SaaS integration. It claims a serious, production-focused, free-software alternative for the same category of work.

## Installation

### Standard WordPress install

1. Upload the plugin to `/wp-content/plugins/open-growth-seo/`.
2. Activate **Open Growth SEO** in wp-admin.
3. Open **Open Growth SEO > Setup Wizard**.
4. Configure:
   - search appearance
   - schema defaults
   - bots and robots policies
   - integrations
   - diagnostics preferences

### Recommended first-run flow

1. Run the Setup Wizard.
2. Review Search Appearance.
3. Review Schema defaults and CPT mappings.
4. Run an Audit.
5. Review AEO, GEO, and SFO workspaces.
6. Check Tools/Support diagnostics.

## Schema Workflow With CPTs

Recommended operating model:

### Novice

- leave schema on `Auto`
- use industry presets only when the CPT structure is obvious

### Intermediate

- set default schema per fixed-purpose `CPT`
- keep editor overrides for exceptions only

### Advanced

- use stable post-type defaults
- use per-post override only when the page visibly diverges from the CPT norm
- inspect saved JSON-LD per post
- export/import mappings across environments and sites

## Free Software Statement

Open Growth SEO is distributed under the GNU General Public License version 3 or later.

This means, in practical terms:

- you may use it for any purpose
- you may inspect the source code
- you may modify it
- you may redistribute original or modified versions under GPL-compatible terms

This project is intended to be a **free-software alternative** to commercial WordPress SEO stacks, not a crippled teaser for a proprietary core.

## Intended Users

- agencies managing many WordPress sites
- in-house SEO and technical content teams
- universities and structured-content publishers
- SaaS companies with docs and API surfaces
- operators who want REST and CLI control
- site owners who prefer GPL software over locked premium stacks

## Quality And Operational Focus

This plugin is built around:

- modular architecture
- regression coverage
- admin ergonomics
- support diagnostics
- REST and CLI operability
- release packaging

It is designed to be maintainable and auditable, not just feature-heavy.

## License

Licensed under **GNU GPL v3.0 or later**.

See:

- [LICENSE](LICENSE)
- [GNU GPL 3.0](https://www.gnu.org/licenses/gpl-3.0.html)

## Company

Open Growth Solutions  
[http://opengrowthsolutions.com](http://opengrowthsolutions.com)
