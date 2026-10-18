# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.1.0] - 2026-10-18

First release: ingest API v1, grouping, panel, alerts, digest, retention, Docker and a production guide.

### Added
- Docker (stage C6): `Dockerfile` and `docker-compose.yml` (web app, queue worker, scheduler, MySQL) for a two-minute trial, built and smoke-tested in CI.
- `docs/deploy.md`: step-by-step production guide for Ubuntu 22.04 with Apache, PHP-FPM 8.3 and MySQL (HTTPS, cron, systemd worker, update script, backups, troubleshooting).
- `.github/labels.yml` with the project's issue labels.
- Project documentation, architecture, protocol v1 and build plan.
- Laravel 13 application skeleton with Filament 5 panel at `/` (own login, NightGuardian branding, indigo, dark mode, optional app-based 2FA).
- `nightguardian:install` command: requirement checks, migrations, first super admin, scheduler and queue worker hints.
- `users.is_super_admin`, `locale` and `timezone` columns; locale middleware for the panel; `lang/en` and `lang/es`.
- `config/nightguardian.php` with ingest limits, defaults, alert settings and channel registry.
- Tooling: Pest, Laravel Pint, Larastan (level 6); composer scripts `test`, `lint`, `lint:fix`, `analyse`.
- Data model (migrations, models, factories) for projects, memberships, invitations, project tokens, issues, fingerprints, events, feedback, deploys and alerts, as described in `docs/data-model.md`.
- Enums `IssueStatus`, `ProjectRole`, `Level` and `EventType`.
- Policies for projects, issues, alert channels and project tokens (super admin, admin, member, viewer).
- Filament panel (stage C4):
  - Dashboard with a card per project (open issues, new today, events in 24 h, 7-day chart).
  - Triage list with filters (project, status, environment, type, level, release), sorting and bulk resolve / ignore.
  - Issue detail: latest event with stack trace (in-app frames highlighted, code context), request, user, tags and context; tabs for events, feedback and activity; actions resolve, resolve in release, ignore 1 h / 24 h / forever, reopen and merge.
  - Event detail page and global search by reference code `NG-XXXX-XXXX` (case, dashes and O/I/L typos tolerated) and by issue title.
  - Projects: settings, members, invitations and tokens; creating a token shows it once with the `.env` block and `composer require nightguardian/laravel`.
  - Invitation links (signed, 7 days, single use) and the acceptance page, for projects and for the instance.
  - Deploys list and users management (super admins only).
  - `issue_activities` table with the status history of each issue (including automatic regressions).
  - English and Spanish translations; authorization tests per role.
- Alerts, digest and retention (stage C5):
  - `AlertChannel` interface, `TelegramChannel` (Bot API, HTML, 10 s timeout) and `MailChannel`, channel registry in `config/nightguardian.php`.
  - `EvaluateAlerts` and `SendAlert` jobs implementing `docs/alerts.md`: ignored issues, never/always notify and critical-level rules, channel minimum level and toggles, per-issue cooldown, hourly cap per project with a "N alerts suppressed" note, threshold alerts, one-time "connection OK" notice; 3 tries with 30 / 120 / 600 s backoff.
  - Alert channels and rules tabs in the project (with "Send test"); alert settings (language, cooldown, cap, threshold). Telegram tokens are stored encrypted, never shown again and never written to logs or error messages.
  - Daily digest per project at its digest time and time zone (`nightguardian:digest`), threshold evaluation (`nightguardian:thresholds`) and retention pruning in chunks plus 90-day alert logs (`nightguardian:prune`), all registered in the scheduler.
  - English and Spanish messages (by project language).
- `DemoSeeder` (2 projects, 3 users, 15 issues with events, feedback and deploys), run by `php artisan db:seed`.
- Ingest API v1 (`POST /api/v1/ingest`): bearer token authentication (sha256, cached 60 s), gzip with a 5 MB inflate cap, batch and per-event validation, all nine event types, idempotent storage by event id, per-project rate limit (429 + `Retry-After`), per-IP limit for invalid tokens, and the `X-NightGuardian-Dry-Run` header.
- Default grouping (`DefaultFingerprinter`, `Normalizer`) and issue status transitions (new, regressed, resolved, ignored) as described in `docs/grouping.md`.
- Server-side `Scrubber` (key denylist, card numbers, bearer tokens, JWTs, private keys) with configurable extra keys.
- GitHub Actions CI: lint, analyse and tests on PHP 8.3/8.4 with MySQL 8.0 and MariaDB 10.11.
