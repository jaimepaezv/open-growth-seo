# AEO Module QA Smoke

1. Open `Open Growth SEO > Content Controls` and verify AEO analysis table appears for recent content.
2. Confirm each row shows clarity, answer-first status, intent signals, and top recommendation.
3. Open a post editor and verify metabox/sidebar show AEO analysis with actionable recommendations and follow-up questions.
4. Edit content to add an answer-first paragraph and list/steps; save and verify analysis updates accordingly.
5. Add mostly image-only content with little text; verify non-text dependency recommendation appears.
6. Add internal links and verify internal linking recommendation is reduced/removed.
7. Call `GET /wp-json/ogs-seo/v1/aeo/analyze?post_id=<id>` and verify structured response fields (summary/signals/follow_up_questions/recommendations).
8. Run `wp ogs-seo aeo analyze --post_id=<id>` and verify concise actionable output.
9. Run `wp ogs-seo audit run` and verify AEO checks produce issues only when signals are weak.
10. Verify copy does not promise ranking or guaranteed AI inclusion.
