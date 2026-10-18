# Roadmap

Legend: ✅ done · 🚧 in progress · 📋 planned · 🙋 help wanted (great for contributors)

## v0.1 — "First night shift" (MVP)

- ✅ Ingest API v1 (batch, gzip, token auth, dry-run, idempotent)
- ✅ Grouping into issues with smart fingerprints; statuses new / regressed / resolved / ignored
- ✅ Filament panel: dashboard, projects, tokens, members and invitations, triage, issue detail, events, feedback, deploys, users
- ✅ Search by end-user reference code
- 🚧 Alerts: Telegram + e-mail, min level per channel, rules, cooldowns, daily digest
- 🚧 Retention pruning
- 🚧 Installer command + Docker Compose
- 🚧 Laravel SDK 2.x (Laravel 9–13) and 1.x (Laravel 5.8–8)
- 🚧 End-user error page with reference code and feedback form

## v0.2 — "Better eyes"

- 📋 Livewire / AJAX error modal in the Laravel SDK
- 📋 Performance views: slowest routes and queries with p50/p95
- 📋 Release health: error rate per deploy
- 📋 Issue assignment and comments
- 🙋 Slack, Discord, Microsoft Teams and generic webhook alert channels
- 🙋 More translations (pt-BR, fr, de, it)

## v0.3 — "Beyond Laravel"

- 🙋 `nightguardian/php` SDK (plain PHP, any framework)
- 🙋 `nightguardian-node` SDK
- 🙋 `nightguardian-python` SDK
- 📋 Browser JavaScript errors
- 📋 Log-file agent: tails `laravel.log` on a server for apps you can't modify
- 📋 Sentry-protocol compatible ingest endpoint (accept events from Sentry SDKs)

## Later

- 📋 Uptime / heartbeat checks (cron monitoring)
- 📋 Request tracing (request → queries → jobs) Nightwatch-style
- 📋 PostgreSQL support 🙋
- 📋 AI-assisted issue summaries with a self-hosted LLM (opt-in)
- 📋 MCP server so coding agents can query issues

Have an idea? Open a discussion. Want to own an item marked 🙋? Comment on its issue.
