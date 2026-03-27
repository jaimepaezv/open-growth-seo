# Meta Robots and Snippet Controls Smoke

1. Open `Open Growth SEO > Search Appearance` and set global defaults for:
   - default robots
   - nosnippet/noarchive/notranslate
   - max-snippet/max-image-preview/max-video-preview
   - unavailable_after
2. Save and verify no validation errors. If invalid date is entered for unavailable_after, verify warning and clear behavior.
3. Set a post type override for snippet controls and save.
4. Edit a post of that type in Gutenberg:
   - confirm controls exist (nosnippet, max-snippet, max-image-preview, max-video-preview, noarchive, notranslate, unavailable_after, data-nosnippet IDs)
   - save values
5. Edit same post in Classic Editor metabox and confirm values are visible and editable.
6. Open frontend HTML and verify meta robots directives reflect precedence:
   URL override > post type default > global default.
7. Verify `X-Robots-Tag` header includes supported directives consistently.
8. Enable nosnippet and set max-snippet; verify output prioritizes nosnippet.
9. Add ID list in `data-nosnippet` control and verify matching elements in content render with `data-nosnippet` attribute.
10. Set noindex and verify post is excluded from sitemap output for that URL context.
