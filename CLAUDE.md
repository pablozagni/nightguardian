# CLAUDE.md — NightGuardian collector

You are building the **collector** of NightGuardian: a self-hosted, open source (MIT) error and health
monitoring app. Repo: github.com/pablozagni/nightguardian. Local path: `C:\laragon\www\nightguardian\collector`
(Windows 11, Laragon, PHP 8.3, MySQL). The sibling folder `..\laravel` is the Laravel SDK repo — do not
modify it from here.

## Read first
1. `docs/architecture.md` 2. `docs/protocol/v1.md` 3. `docs/data-model.md` 4. `docs/grouping.md`
5. `docs/alerts.md` 6. `docs/security.md` 7. `docs/build-plan.md` (do ONLY the stage you are asked for)

## Stack (fixed — changing it needs an ADR)
Laravel 13, PHP 8.3+, Filament 5, MySQL 8 / MariaDB 10.6+, `database` queue, Pest, Pint, Larastan (level 6).
No Redis, no external SaaS, no paid services.

## Non-negotiable rules
- The ingest endpoint is a public contract (`/api/v1`). Additive changes only. Any change → update
  `docs/protocol/v1.md`, the JSON schema, tests, and add an ADR in `docs/adr/`.
- Ingest must be stateless (no session, no CSRF, no cookies) and fast. Heavy work (alerts) goes to the queue.
- Never render event data unescaped. No `{!! !!}` with payload data.
- Every feature has Pest tests. Every bug fix has a regression test. `composer test` and `composer lint` must pass.
- Authorization through Policies; test viewer/member/admin boundaries.
- All UI strings translated (`lang/en`, `lang/es`). No hardcoded user-facing text.
- Nothing specific to any company. This is a generic product.
- Secrets only in `.env` (git-ignored). `.env.example` with empty values. Never commit real tokens.
- Keep `README.md`, `README.es.md`, `CHANGELOG.md` (Unreleased section) and `ROADMAP.md` in sync with what you build.

## Code conventions
- English for code, comments, commits, docs. Conventional Commits.
- Domain code under `app/Ingest`, `app/Grouping`, `app/Alerts`, `app/Privacy`; enums in `app/Enums`.
- Small, focused classes; constructor injection; no facades inside domain classes (ok in controllers/Filament).
- Prefer readability over cleverness: contributors are the audience.

## Git
- Default branch `main`. Commit at the end of each stage (or sub-step if large) and push:
  `git add -A && git commit -m "<type>: <summary>" && git push`.
- End commit messages with the trailer the session provides (Co-Authored-By), if any.

## Environment notes (developer machine)
- Windows: give commands for cmd/PowerShell. The developer never uses vim.
- Local URL: `http://collector.nightguardian.test` (Laragon vhost, see stage 0 in build-plan).
- Local DB: `nightguardian` on Laragon MySQL (read credentials from Laragon defaults; ask if unsure).
