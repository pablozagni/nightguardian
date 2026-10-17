# Changelog

All notable changes to this project are documented here.
Format: [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Versioning: [SemVer](https://semver.org).

## [Unreleased]

### Added
- Project documentation, architecture, protocol v1 and build plan.
- Laravel 13 application skeleton with Filament 5 panel at `/` (own login, NightGuardian branding, indigo, dark mode, optional app-based 2FA).
- `nightguardian:install` command: requirement checks, migrations, first super admin, scheduler and queue worker hints.
- `users.is_super_admin`, `locale` and `timezone` columns; locale middleware for the panel; `lang/en` and `lang/es`.
- `config/nightguardian.php` with ingest limits, defaults, alert settings and channel registry.
- Tooling: Pest, Laravel Pint, Larastan (level 6); composer scripts `test`, `lint`, `lint:fix`, `analyse`.
- Data model (migrations, models, factories) for projects, memberships, invitations, project tokens, issues, fingerprints, events, feedback, deploys and alerts, as described in `docs/data-model.md`.
- Enums `IssueStatus`, `ProjectRole`, `Level` and `EventType`.
- Policies for projects, issues, alert channels and project tokens (super admin, admin, member, viewer).
- `DemoSeeder` (2 projects, 3 users, 15 issues with events, feedback and deploys), run by `php artisan db:seed`.
- Ingest API v1 (`POST /api/v1/ingest`): bearer token authentication (sha256, cached 60 s), gzip with a 5 MB inflate cap, batch and per-event validation, all nine event types, idempotent storage by event id, per-project rate limit (429 + `Retry-After`), per-IP limit for invalid tokens, and the `X-NightGuardian-Dry-Run` header.
- Default grouping (`DefaultFingerprinter`, `Normalizer`) and issue status transitions (new, regressed, resolved, ignored) as described in `docs/grouping.md`.
- Server-side `Scrubber` (key denylist, card numbers, bearer tokens, JWTs, private keys) with configurable extra keys.
- `EvaluateAlerts` job (stub that only logs; the real implementation arrives with the alerts stage).
- GitHub Actions CI: lint, analyse and tests on PHP 8.3/8.4 with MySQL 8.0 and MariaDB 10.11.
