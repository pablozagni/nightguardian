# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

## [0.1.2] - 2026-10-18

### Fixed
- Projects → Edit → Alert channels → Create: with Type = Telegram, filling "Bot token" and "Chat ID" ended in "The bot token field is
  required" / "The chat ID field is required". The `config` state was `null` until a type was chosen, and the browser cannot write
  `config.bot_token` into a `null`, so the typed values never reached the server. Choosing a type now seeds `config` with the
  fields of that type (and resets it when the type changes). E-mail channels were affected the same way.
- Editing a channel opened with empty settings (for example a blank "Chat ID"): `config` is a hidden attribute of the model, so it was
  missing from the record data of the form. The form now reads it from the model; a blank secret still means "keep the current one",
  and the other fields validate normally.
- The regression tests drive the real form the way a browser does (pick the type, then fill the dynamic fields) for Telegram and
  e-mail, on create and on edit; the old tests sent a ready-made `config` and could not see either problem.

## [0.1.1] - 2026-10-18

### Added
- **Public demo mode** (`NIGHTGUARDIAN_DEMO_MODE=true`, off by default, see `docs/demo-mode.md`): a read-only visitor account with its
  credentials and an "Enter the demo" button on the login page, a permanent "Live demo" banner (English/Spanish, login page
  language follows the browser), no profile/password/two-factor changes for the visitor, a lower ingest limit
  (`NIGHTGUARDIAN_DEMO_RATE_LIMIT`, 120 events per minute per project) and the `nightguardian:demo-reset` command, scheduled
  daily at 04:00, that wipes everything except the configured administrator and rebuilds realistic data plus the "Demo app"
  project with the token from `NIGHTGUARDIAN_DEMO_INGEST_TOKEN`.

### Fixed
- `nightguardian:demo-reset` failed on production installs (`composer install --no-dev`) with `Call to undefined function Database\Factories\fake()`:
  the demo data came from Eloquent factories, which need Faker, a development dependency. The demo seeding now creates its
  models directly with realistic hand-written data, so Faker stays a dev dependency. Regression tests added, plus a CI job
  (`production-install`) that runs `composer install --no-dev` and `nightguardian:demo-reset` against MySQL.
- `nightguardian:install` printed the cron and systemd examples with a bare `php` and `/usr/bin/php`; they now use the PHP binary
  that runs the command and the real project path, which matters on servers with several PHP versions.
- `docs/deploy.md` and `docs/demo-mode.md` call `php8.3` (and Composer through it) explicitly instead of the default `php`.

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
