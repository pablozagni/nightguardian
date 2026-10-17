# Architecture

## 1. Goals

1. **Self-hosted and free.** One Laravel app + MySQL. No Redis, no external services required.
2. **API-first.** The collector knows nothing about Laravel. Any language can send events.
3. **Never harm the monitored app.** Failure of the collector must be invisible to end users.
4. **Signal over noise.** Group, then alert only on meaningful changes.
5. **Easy to contribute.** Plain Laravel conventions, Filament for UI, Pest for tests.
6. **Small now, extensible later.** v0.1 is errors-first; the event envelope already supports
   performance, deploys and more without breaking changes.

## 2. Components

```
┌──────────────────── Monitored application ────────────────────┐
│  SDK (e.g. nightguardian/laravel)                             │
│   capture → scrub → build event → buffer (memory)             │
│   after response: send batch ──► OK                           │
│                         └─ fail ──► spool on disk ─► retry    │
│   circuit breaker: collector down → only spool, retry later   │
└───────────────────────────────┬───────────────────────────────┘
                                │ HTTPS POST /api/v1/ingest (gzip JSON, Bearer token)
┌───────────────────────────────▼──────── Collector ────────────┐
│  Ingest controller (stateless, no session)                    │
│   auth token → size/rate limits → validate → scrub again      │
│   → idempotent store (event_uuid) → fingerprint → upsert issue│
│   → dispatch EvaluateAlerts job                               │
│                                                               │
│  Queue worker (database driver)   Scheduler (cron each minute)│
│   - send alerts                    - daily digests            │
│                                    - retention pruning        │
│                                    - threshold windows        │
│                                                               │
│  Filament panel  (projects, triage, issue detail, settings)   │
│  MySQL 8 / MariaDB 10.6+                                      │
└───────────────────────────────────────────────────────────────┘
```

## 3. Collector stack

| Concern | Choice | Why |
|---|---|---|
| Framework | Laravel 13, PHP 8.3+ | Most contributors know it |
| UI | Filament 5 | Fast CRUD/tables, auth, 2FA, i18n built-in |
| DB | MySQL 8 / MariaDB 10.6+ | Ubiquitous on cheap VPSs. Postgres = contribution welcome |
| Queue | `database` driver | No Redis needed |
| Tests | Pest | Readable, popular |
| Style | Laravel Pint | Zero-config |
| Static analysis | Larastan level 6 | Catch bugs in PRs from strangers |

Requirements to run: PHP 8.3+, MySQL/MariaDB, a cron entry for `schedule:run`, and one
`queue:work` process (systemd or supervisor). Both lines are printed by the installer.

## 4. Ingest path (hot path)

Must stay fast and cheap — it is hit by every monitored app.

1. Route `POST /api/v1/ingest` in `routes/api.php`, **no session, no CSRF, no cookies**.
2. Read `Authorization: Bearer <token>`; look up `sha256(token)` in `project_tokens` (cached 60s).
3. Reject if body > 1 MB compressed / 5 MB uncompressed, or > 100 events per batch.
4. Decode gzip if `Content-Encoding: gzip`.
5. Per-project rate limit (default 600 events/min, configurable). Over the limit → `429` with `Retry-After`.
6. Validate each event against protocol v1. Invalid events are rejected individually; valid ones are accepted.
7. Scrub again (server-side denylist) — defense in depth.
8. In one transaction per event: insert into `events` (unique `event_uuid` → duplicates are ignored, still reported as accepted),
   resolve fingerprint → `issues` upsert (counters, `last_seen_at`, status changes such as resolved → regressed).
9. Dispatch `EvaluateAlerts` for issues that are new, regressed, or crossed a threshold.
10. Respond `202` with per-event results (see protocol).

Header `X-NightGuardian-Dry-Run: 1` runs steps 2–7 and returns the validation report without storing anything.
This is the tool for SDK authors.

## 5. Grouping

See [grouping.md](grouping.md). Summary: fingerprint = hash of event type + exception class +
first **in-app** frame (normalized file + function), not the line number, so deploys that move code
don't create false "new" issues. SDKs may send an explicit fingerprint. Issues can be merged in
the panel (fingerprint aliases).

## 6. Alerts

See [alerts.md](alerts.md). Channels per project (Telegram, mail in v0.1), each with a minimum level.
Triggers: new issue, regression, threshold (N events in M minutes). Cooldown per issue, hourly cap per
project, "always notify" / "never notify" rules, daily digest.

## 7. Access model

- **Super admin** (instance owner): manages users and all projects.
- **Project roles**: `admin` (settings, tokens, members, channels), `member` (resolve, ignore, merge, comment),
  `viewer` (read only).
- No public registration. Admins create **invitation links** (signed URL, 7 days). No mail server required.
- Optional 2FA (Filament).

## 8. Data lifecycle

- Events are pruned after `retention_days` (project setting, default 30) by a scheduled job, in chunks.
- Issues, counters and deploys are kept.
- Feedback attached to pruned events is kept on the issue.

## 9. Extensibility points (stable interfaces)

| Interface | Purpose |
|---|---|
| `App\Ingest\EventTypes\EventType` | Validate/normalize/fingerprint one event `type`. New types = new class. |
| `App\Alerts\Channels\AlertChannel` | Send a notification. New channel = new class + config form. |
| `App\Grouping\Fingerprinter` | Swap grouping strategy. |
| `App\Privacy\Scrubber` | Server-side scrubbing rules. |

## 10. Non-goals for v0.1

Distributed tracing, metrics time-series DB, multi-tenant SaaS, organizations/teams, Postgres.
The envelope is designed so they can be added without breaking `/api/v1`.

## 11. Related docs

[protocol/v1.md](protocol/v1.md) · [data-model.md](data-model.md) · [grouping.md](grouping.md) ·
[alerts.md](alerts.md) · [security.md](security.md) · [sdk-authors.md](sdk-authors.md) · [adr/](adr/)
