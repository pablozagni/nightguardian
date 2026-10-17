# Build plan — collector

Each stage is one Claude Code session/prompt. Finish a stage completely (tests green, docs updated,
committed and pushed) before starting the next. Do not implement future stages early.

## Stage C0 — Repository bootstrap
- `git init -b main`, add `.gitignore` (Laravel default + `.env`, `/vendor`, `/node_modules`, `.idea`, `.vscode`).
- Add remote `https://github.com/pablozagni/nightguardian.git`, commit existing docs, push.

## Stage C1 — Laravel skeleton + tooling
- Create Laravel 13 in a temp folder and move it into this repo **without overwriting existing docs**
  (`README*.md`, `LICENSE`, `CHANGELOG.md`, `ROADMAP.md`, `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`,
  `SECURITY.md`, `CLAUDE.md`, `docs/`, `.github/`).
- Install Filament 5 (panel at `/`, login at `/login`), Pest, Pint, Larastan.
- `composer test`, `composer lint`, `composer analyse` scripts.
- `config/nightguardian.php` (ingest limits, defaults for retention, cooldowns, channels registry).
- Locales `en` and `es`; user `locale` and `timezone` columns; locale middleware for the panel.
- `users.is_super_admin`. Filament 2FA enabled (optional per user).
- Command `nightguardian:install`: checks PHP/DB, runs migrations, asks for admin name/e-mail/password,
  creates super admin, prints the cron line and the queue worker command (systemd unit example).
- GitHub Actions: `tests.yml` (PHP 8.3 and 8.4, MySQL 8 service) running lint, analyse, test.
- Laragon vhost `collector.nightguardian.test` → `public/` (see PROMPTS.md stage 0 notes).
- Tests: install command (non-interactive flags), login, locale switch.

## Stage C2 — Data model
- Migrations, models, enums, factories, policies exactly as `docs/data-model.md`.
- `DemoSeeder`: 2 projects, 3 users with different roles, ~15 issues across statuses, events, feedback, deploys.
- Tests: relationships, enum casts, policies matrix.

## Stage C3 — Ingest API v1
- `POST /api/v1/ingest` per `docs/architecture.md` §4 and `docs/protocol/v1.md`.
- `App\Ingest`: `TokenAuthenticator`, `PayloadDecoder` (gzip with 5 MB cap), `BatchValidator`,
  one `EventType` class per type, `EventStore` (idempotent), `IssueUpdater` (status transitions).
- `App\Grouping\DefaultFingerprinter` + `Normalizer` per `docs/grouping.md`.
- `App\Privacy\Scrubber` per `docs/security.md`.
- Rate limits (per project, per IP for bad tokens). Dry-run header.
- Dispatch `EvaluateAlerts` job (stub implementation that only logs — real in C5).
- Tests: every status code in protocol §5, idempotency, gzip, limits, each event type with the
  examples in `docs/protocol/examples`, all grouping fixtures, scrubbing.

## Stage C4 — Panel
- Filament resources/pages:
  - **Dashboard**: per-project cards (open issues, new today, events 24 h), small 7-day chart.
  - **Projects**: CRUD (admin), settings tab (retention, timezone, digest time, thresholds), members tab
    (roles, invitation links), tokens tab (create shows the token once with copy button + install snippet
    for the Laravel SDK; revoke).
  - **Triage** (issues list): filters project/status/environment/type/level/release, sort by last seen /
    events; bulk resolve/ignore.
  - **Issue detail**: header (status, counts, first/last seen, releases), actions (resolve, resolve in next
    release, ignore 1h/24h/forever, reopen, merge), tabs: Overview (latest event stack trace with in-app
    frames highlighted and code context, request, user, tags, context), Events (paginated), Feedback,
    Activity (status changes).
  - **Deploys** list per project.
  - **Users** (super admin only).
  - Global search by reference code `NG-XXXX-XXXX` → opens the event.
- Invitation acceptance page (signed URL, sets password).
- Tests: authorization for each role, token shown once, merge, search by reference.

## Stage C5 — Alerts, digest, retention
- `AlertChannel` interface, `TelegramChannel`, `MailChannel`, channels registry.
- Alert channels and rules UI in project settings (with "Send test").
- `EvaluateAlerts` + `SendAlert` jobs implementing `docs/alerts.md` (filters, cooldown, hourly cap,
  suppressed count). `AlertMessage` translated by project language.
- Scheduler: `nightguardian:digest` (every minute, sends due digests), `nightguardian:prune` (daily),
  threshold evaluation.
- Tests: every filter path, cooldown, cap, rules, digest content, prune chunks. Telegram via `Http::fake()`.

## Stage C6 — Packaging & polish
- `Dockerfile` + `docker-compose.yml` (app, mysql, worker, scheduler) for a 2-minute trial.
- Production guide `docs/deploy.md`: Apache/Nginx vhost, PHP-FPM, cron, systemd worker unit, backups, upgrades
  (`git pull && composer install --no-dev && php artisan migrate --force && php artisan optimize`).
- README screenshots placeholders (`docs/img/`), issue labels list (`good first issue`, `help wanted`, `sdk`,
  `alerts`, `i18n`), first release notes, tag `v0.1.0`.
