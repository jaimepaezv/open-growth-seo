# Schema Engine QA Smoke

1. Open `Open Growth SEO > Schema` and verify core graph toggles and content-type toggles are available.
2. Configure Organization and optional LocalBusiness details, save, and confirm no warnings for valid values.
3. Open a singular post and verify JSON-LD contains `WebPage` and contextual main entity.
4. For a blog post, verify Article/BlogPosting/NewsArticle selection follows context or override.
5. For WooCommerce product, verify Product + Offer and optional AggregateRating output when reviews exist.
6. For FAQ-like content, verify FAQPage appears only when visible Q&A pairs are detected.
7. For Q&A-like content, verify QAPage structure includes main question and accepted answer.
8. For event/job/recipe/software/dataset-targeted content, verify schema is emitted only when required visible signals/fields exist.
9. Verify schema is skipped or warnings generated when required fields are missing (no schema basura).
10. Confirm no duplicate nodes by `@id` and no duplicate script output.
11. Open `GET /wp-json/ogs-seo/v1/schema/inspect` and verify payload, errors, and warnings are useful.
12. Run `wp ogs-seo schema status` and `wp ogs-seo audit run` to verify diagnostics and schema health checks.
13. Verify safe mode suppresses schema output when another SEO plugin is active.
