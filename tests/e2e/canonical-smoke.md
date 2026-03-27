# Canonicalization Smoke

1. Enable canonical tags in Search Appearance.
2. Verify frontend emits exactly one canonical tag on singular content.
3. Set manual canonical override for a post with absolute URL and verify output uses override.
4. Set root-relative canonical override and verify it is normalized to absolute URL.
5. Set invalid canonical value (non-http scheme) and verify it is ignored/cleared after save.
6. Verify paginated archive canonical points to paginated URL.
7. Verify search canonical preserves search query while removing non-essential tracking parameters.
8. Verify taxonomy and author archives emit stable self-canonicals.
9. Set noindex + different canonical and run audit; verify canonical conflict issue is reported.
10. Set canonical override to unreachable internal URL and run audit; verify issue is reported.
