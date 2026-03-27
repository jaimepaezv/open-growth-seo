# GEO Module QA Smoke

1. Open `Open Growth SEO > Bots & Crawlers` and verify `GEO Diagnostics` is visible.
2. Confirm bot policy snapshot shows global policy, GPTBot, and OAI-SearchBot values.
3. Verify recent content table includes text visibility, schema-text alignment, citability, semantic clarity, freshness, and top recommendation.
4. Set both GPTBot and OAI-SearchBot to `Disallow`, save, run audit, and verify GEO bot warning appears.
5. Open a post with schema override (e.g., `FAQPage`) but without valid visible Q&A pairs and verify GEO schema-text mismatch signal.
6. Open mostly image-based content with little text and verify weak text visibility recommendation.
7. Add clear headings, facts, and internal links; recheck table and verify GEO recommendations improve.
8. Call `GET /wp-json/ogs-seo/v1/geo/analyze?post_id=<id>` and verify summary/signals/recommendations payload.
9. Run `wp ogs-seo geo analyze --post_id=<id>` and verify actionable output lines.
10. Confirm GEO wording does not promise guaranteed rankings or inclusion.
