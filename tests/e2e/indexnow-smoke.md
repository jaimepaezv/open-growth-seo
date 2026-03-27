# IndexNow Module QA Smoke

1. Enable IndexNow in `Open Growth SEO > Integrations`.
2. Generate key from UI and verify key endpoint (`/<key>.txt`) returns exact key content.
3. Run `Verify Key` action and confirm success/failure state is shown in status table.
4. Save/update a published post and confirm queue pending count increases.
5. Delete/trash a post and confirm deletion URL is queued.
6. Trigger `Process Queue Now` and verify queue decreases on HTTP 200/202 responses.
7. Simulate endpoint/network error and verify retries/backoff behavior updates queue item attempts.
8. Verify deduplication: multiple rapid updates for same URL should not create duplicate queue items.
9. Validate rate limiting prevents excessive immediate submissions.
10. Verify `GET /wp-json/ogs-seo/v1/indexnow/status` and `POST /wp-json/ogs-seo/v1/indexnow/process` (admin-only).
11. Verify WP-CLI: `wp ogs-seo indexnow status|process|verify-key|generate-key`.
12. Confirm plugin frontend remains unaffected if IndexNow is disabled or remote API fails.
