# Sitemaps Module QA Smoke

1. Enable sitemaps in `Open Growth SEO > Sitemaps`.
2. Select at least one public post type.
3. Open `/ogs-sitemap.xml` and verify valid `<sitemapindex>` output.
4. Open `/ogs-sitemap-post.xml` and verify URL entries include truthful `lastmod` values.
5. For high-volume types, open `/ogs-sitemap-post-2.xml` and verify pagination continuity.
6. Set one post to `noindex` in editor and confirm it is absent after cache invalidation.
7. Set canonical override to a different URL and confirm source URL is excluded from sitemap.
8. Confirm password-protected content is excluded.
9. Run `wp ogs-seo sitemap status` and `wp ogs-seo sitemap flush`.
10. Call `GET /wp-json/ogs-seo/v1/sitemaps/status` and `GET /wp-json/ogs-seo/v1/sitemaps/inspect` as admin.
11. Update sitemap-related settings and confirm cache version changes.
12. Run `wp ogs-seo audit run` and verify sitemap runtime consistency check appears when XML is malformed.
