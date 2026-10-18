# Data model (collector)

MySQL 8 / MariaDB 10.6+. Primary keys are ULIDs (`char(26)`) for everything exposed in URLs
(projects, issues, events); auto-increment for internal tables. All timestamps in UTC.

## Tables

### `users`
Laravel default + `is_super_admin` (bool), `locale` (`en`/`es`), `timezone`, 2FA columns (Filament).

### `projects`
| column | type | notes |
|---|---|---|
| id | ulid | |
| name | string(120) | |
| slug | string(120) unique | |
| platform | string(32) null | informational (`laravel`, `php`, …) |
| retention_days | smallint default 30 | |
| timezone | string(64) default app tz | for digests |
| digest_time | time null default `08:00` | null = no digest |
| rate_limit_per_minute | int default 600 | |
| settings | json | thresholds, cooldowns (see alerts.md) |
| archived_at | timestamp null | archived projects reject ingest (403) |

### `project_user`
`project_id`, `user_id`, `role` enum(`admin`,`member`,`viewer`), timestamps. Unique (project_id, user_id).

### `invitations`
`id`, `project_id` null (null = instance-level invite by super admin), `role`, `email` null, `token_hash`,
`expires_at`, `accepted_at`, `created_by`.

### `project_tokens`
| column | notes |
|---|---|
| id | |
| project_id | |
| name | e.g. "production server" |
| token_hash | sha256 of the token, unique. Token shown once at creation: `ng_` + 40 random chars |
| last_used_at | updated at most once per minute |
| revoked_at | |

### `issues`
| column | type | notes |
|---|---|---|
| id | ulid | |
| project_id | | |
| type | string(32) | event type that created it |
| title | string(255) | e.g. `QueryException: SQLSTATE[23000] …` |
| culprit | string(255) null | `App\Http\Controllers\InvoiceController@update` |
| exception_class | string(255) null | |
| level | string(16) | highest level seen |
| status | enum(`new`,`regressed`,`resolved`,`ignored`) | |
| events_count | bigint unsigned | |
| users_count | int unsigned | approximate (distinct user ids, capped) |
| environments | json | list seen |
| first_seen_at / last_seen_at | timestamp | indexed |
| first_release / last_release | string null | |
| resolved_at, resolved_by, resolved_in_release | | |
| ignored_until | timestamp null | null + status ignored = forever |
| last_alerted_at | timestamp null | cooldown |
| merged_into_id | ulid null | when merged |

Indexes: (project_id, status, last_seen_at), (project_id, last_seen_at).

### `issue_fingerprints`
`project_id`, `fingerprint` char(40) (sha1), `issue_id`. Unique (project_id, fingerprint).
Merging issue B into A re-points B's fingerprints to A.

### `events`
| column | type | notes |
|---|---|---|
| id | ulid | |
| uuid | char(36) unique | protocol `id`, idempotency |
| project_id, issue_id (null for deploy) | | |
| type | string(32) | |
| level | string(16) null | |
| reference | char(12) null | indexed — panel global search |
| occurred_at | timestamp(3) | protocol `timestamp` |
| received_at | timestamp(3) | |
| environment, release, server | | |
| sdk_name, sdk_version, platform | | |
| message | string(1024) null | short text for lists |
| request_method, request_route, request_url(1024), status_code | | |
| user_identifier | string(191) null | |
| payload | json | full normalized event (scrubbed) |

Indexes: (project_id, occurred_at), (issue_id, occurred_at), (reference).
Pruned by `occurred_at < now() - retention_days` in chunks of 1,000.

### `feedback`
`id`, `project_id`, `issue_id` null, `event_uuid` null, `reference` null, `comment` text, `email` null, `created_at`.

### `issue_activities`
`id`, `issue_id`, `user_id` null (null = the system, e.g. an automatic regression), `action`
(`resolved`, `resolved_in_release`, `ignored`, `reopened`, `regressed`, `merged`), `meta` json null, `created_at`.
Shown in the *Activity* tab of an issue.

### `deploys`
`id`, `project_id`, `environment`, `release`, `commit` null, `branch` null, `deployer` null, `url` null, `deployed_at`.

### `alert_channels`
`id`, `project_id`, `type` (`telegram`, `mail`, …), `name`, `config` (encrypted json: bot token/chat id, recipients),
`min_level` default `error`, `notify_new` bool, `notify_regressed` bool, `notify_threshold` bool,
`include_digest` bool, `enabled`, `last_error` text null, `last_sent_at`.

### `alert_rules`
`id`, `project_id`, `name`, `field` (`exception_class`, `message`, `route`, `tag:<key>`, `type`, `level`),
`operator` (`equals`, `contains`, `starts_with`, `regex`), `value`, `action` (`always_notify`, `never_notify`,
`set_level_critical`), `position`, `enabled`.

### `alert_logs`
`id`, `alert_channel_id`, `issue_id` null, `kind` (`new`,`regressed`,`threshold`,`rule`,`digest`,`test`),
`status` (`sent`,`failed`,`suppressed`), `error` null, `created_at`. Pruned after 90 days.

## Eloquent conventions

- Models in `app/Models`. Enums in `app/Enums` (`IssueStatus`, `ProjectRole`, `Level`, `EventType`).
- Policies in `app/Policies` for Project, Issue, AlertChannel, ProjectToken.
- Factories for every model; `DemoSeeder` creates 2 projects with realistic issues for screenshots and contributors.
