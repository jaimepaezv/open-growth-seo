# WooCommerce SEO Module Smoke

1. Activate WooCommerce and create one simple product and one variable product.
2. In `Open Growth SEO > Search Appearance`, verify Woo archive robots controls are visible and persist after save.
3. In `Open Growth SEO > Schema`, verify Woo schema controls are visible and persist after save.
4. With `Product schema source = WooCommerce native`, verify Open Growth SEO does not emit duplicate Product schema nodes.
5. Switch to `Product schema source = Open Growth SEO takeover` and verify Product schema includes Offer, price, currency, and availability.
6. For variable product, verify multiple Offer entries are emitted for priced variations.
7. Add ratings/reviews and verify `aggregateRating` and `review` entries are emitted when data exists.
8. Configure return/shipping policy pages in Woo settings and verify policy references appear in Product schema.
9. Verify product/category/tag archive robots behavior follows Woo-specific settings.
10. Run audit and verify Woo findings for missing prices, thin content, and variable-product variation issues are actionable.
11. Deactivate WooCommerce and verify no fatal errors, no notices, and graceful fallback to generic SEO/schema behavior.
