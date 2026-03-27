# Robots Module Smoke

1. Open `Open Growth SEO > Bots & Crawlers`.
2. Confirm mode selector supports Visual (managed) and Expert.
3. In managed mode, set GPTBot/OAI-SearchBot allow/disallow and save.
4. Verify preview for GPTBot and OAI-SearchBot reflects selected directives.
5. Add invalid custom directive and verify save is blocked with validation error.
6. In expert mode, set invalid syntax and verify save is blocked.
7. Set `User-agent: *` + `Disallow: /` on a public site and verify safeguard blocks save.
8. Click `Restore safe defaults` and verify managed defaults are restored.
9. Verify rendered robots output includes sitemap directive.
10. If physical robots.txt exists, verify warning notice is displayed.
