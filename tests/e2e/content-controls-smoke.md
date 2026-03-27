# Content Controls Module Smoke

1. Open a post in Classic Editor and verify all SEO fields are visible and editable.
2. Save post and verify post meta persists for title, description, canonical, index/follow, snippet controls, social fields, schema override.
3. Open same post in Gutenberg and verify sidebar fields show saved values.
4. Update values in Gutenberg and save; verify values persist in post meta.
5. Confirm `ogs_seo_robots` is synchronized from index/follow controls.
6. Verify frontend title/meta/canonical/robots output follows per-post overrides over global defaults.
7. Verify social override fields affect OG/Twitter title/description/image.
8. Confirm autosave/revision does not commit SEO meta unexpectedly.
9. Confirm quick edit/bulk edit do not overwrite SEO meta (no nonce = no save).
10. Check editor responsiveness remains acceptable with panel open.
