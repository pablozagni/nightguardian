# Alerts

## Triggers

| Trigger | When |
|---|---|
| `new` | First event of a new issue |
| `regressed` | Event on a resolved issue (or expired ignore) |
| `threshold` | ≥ `threshold_count` events in `threshold_minutes` for one issue (default 50 in 60) — once per window |
| `rule` | An `always_notify` rule matched (bypasses new/regressed requirement) |
| `digest` | Daily summary per project |

## Filters, in order

1. Issue status `ignored` (active) → never.
2. `never_notify` rule matched → never.
3. Channel `min_level` (PSR-3 order) — event level must be ≥. A `set_level_critical` rule raises the level.
4. Channel toggles (`notify_new`, `notify_regressed`, `notify_threshold`).
5. **Cooldown per issue** (`settings.cooldown_minutes`, default 60). `always_notify` rules use a
   minimum 5-minute cooldown to prevent floods.
6. **Hourly cap per project** (`settings.max_alerts_per_hour`, default 20). Excess alerts are
   `suppressed`; the next allowed alert includes "N alerts suppressed in the last hour".

Default levels: exceptions → `error`; slow request/query → `warning` (so they don't alert with the
default `min_level = error`); job/schedule failures → `error`.

## Implementation notes

- Evaluation runs in the queued job `EvaluateAlerts` (`App\Alerts\AlertEvaluator`). The ingest dispatches it for new and
  regressed issues, for `test` events, and for every grouped event when the project has an enabled `always_notify` rule.
- The cooldown uses `issues.last_alerted_at`; the hourly cap and the suppressed counter use the application cache
  (fixed one-hour buckets), and the threshold window uses a cache lock per issue.
- Project settings (`projects.settings` json): `language` (`en`/`es`), `cooldown_minutes`, `max_alerts_per_hour`,
  `threshold_count`, `threshold_minutes`, `digest_skip_empty`.
- The scheduler runs `nightguardian:digest` and `nightguardian:thresholds` every minute and `nightguardian:prune` daily
  at 03:30. Without a worker and cron nothing is sent: see "Running the queue and the scheduler" in the README.

## Message content

Short, actionable, translated to the project's language (setting, default `en`):

```
🔴 NEW · Acme Billing (production)
QueryException: Integrity constraint violation … 'description' cannot be null
InvoiceController@update · release a1b2c3d
3 events · 1 user · first seen 2 min ago
→ https://ng.example.com/issues/01JAB…
```

Icons: 🔴 new · 🟠 regressed · 📈 threshold · ⚡ rule · 📋 digest.

## Daily digest

At `digest_time` in the project timezone: new issues, regressed, top 5 by events, totals vs previous
day, resolved count, last deploys. Not sent if there was zero activity (configurable).

## Channels

Interface `App\Alerts\Channels\AlertChannel`:

```php
interface AlertChannel
{
    public static function type(): string;                 // 'telegram'
    public static function label(): string;                // translated
    public static function configForm(): array;            // Filament form components
    public function send(AlertMessage $message, array $config): void; // throws on failure
    public function test(array $config): void;             // "Send test" button
}
```

Register in `config/nightguardian.php` → `alert_channels`. v0.1 ships `TelegramChannel` (Bot API
`sendMessage`, HTML parse mode, 10 s timeout) and `MailChannel` (Laravel mailer).
Slack, Discord, Teams and generic webhook are great first contributions.

Sending happens in queued job `SendAlert` (3 tries, backoff 30/120/600 s). Failures are stored in
`alert_logs` and in `alert_channels.last_error`, shown in the panel.
