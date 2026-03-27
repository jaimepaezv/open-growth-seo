# Hreflang Module QA Smoke

1. Enable hreflang in `Open Growth SEO > Search Appearance`.
2. Verify detected provider/languages/status table renders without notices.
3. Open `GET /wp-json/ogs-seo/v1/hreflang/status` as admin and verify payload includes provider, languages, sample alternates, and errors.
4. With Polylang or WPML active, open a translated singular URL and verify alternates are emitted in `<head>` with valid hreflang codes.
5. Confirm `x-default` behavior for auto/custom/none modes.
6. Set invalid manual map lines and verify they are ignored and warnings appear on save.
7. Verify no hreflang output when another SEO plugin is active and safe mode is enabled.
8. Verify pages with canonical override to a different URL do not emit hreflang alternates.
9. Run `wp ogs-seo hreflang status` and `wp ogs-seo audit run`; confirm hreflang health findings are actionable.
10. In non-multilingual site without manual map, verify hreflang remains safely silent (no invalid tags emitted).
