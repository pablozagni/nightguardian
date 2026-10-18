<?php

use App\Alerts\Channels\MailChannel;
use App\Alerts\Channels\TelegramChannel;

return [

    /*
    | Ingest API limits (see docs/protocol/v1.md).
    */
    'ingest' => [
        'max_compressed_bytes' => 1024 * 1024,
        'max_uncompressed_bytes' => 5 * 1024 * 1024,
        'max_events_per_request' => 100,
        'max_event_bytes' => 256 * 1024,
        'max_json_depth' => 32,
        'rate_limit_per_minute' => 600,
        'invalid_token_rate_limit_per_minute' => 20,
    ],

    /*
    | Extra denylist keys for the server-side scrubber (case-insensitive substring match), added to the
    | built-in list of docs/security.md. Example: ['cuit', 'cbu'].
    */
    'scrub' => [
        'extra_keys' => [],
    ],

    /*
    | Defaults applied to new projects.
    */
    'defaults' => [
        'retention_days' => 30,
        'digest_time' => '08:00',
    ],

    /*
    | Public demo mode (docs/demo-mode.md). Off by default. When on: a read-only visitor account is offered on the login
    | page, a banner says the data resets every night, the visitor cannot change their password/profile/2FA, the
    | ingest limit is lower and `nightguardian:demo-reset` is scheduled daily.
    */
    'demo' => [
        'enabled' => (bool) env('NIGHTGUARDIAN_DEMO_MODE', false),
        'visitor_email' => env('NIGHTGUARDIAN_DEMO_VISITOR_EMAIL', 'visitor@demo.example.com'),
        'visitor_password' => env('NIGHTGUARDIAN_DEMO_VISITOR_PASSWORD', 'visitor-demo'),
        'admin_email' => env('NIGHTGUARDIAN_DEMO_ADMIN_EMAIL'),
        'ingest_token' => env('NIGHTGUARDIAN_DEMO_INGEST_TOKEN'),
        'ingest_rate_limit_per_minute' => (int) env('NIGHTGUARDIAN_DEMO_RATE_LIMIT', 120),
        'reset_time' => env('NIGHTGUARDIAN_DEMO_RESET_TIME', '04:00'),
    ],

    /*
    | Retention: events are deleted in chunks of this size (nightguardian:prune).
    */
    'retention' => ['chunk' => 1000],

    /*
    | Alert defaults (see docs/alerts.md).
    */
    'alerts' => [
        'cooldown_minutes' => 60,
        'always_notify_cooldown_minutes' => 5,
        'max_alerts_per_hour' => 20,
    ],

    /*
    | Registered alert channels: type => class implementing App\Alerts\Channels\AlertChannel.
    | Channel classes are added in the alerts stage.
    */
    'alert_channels' => [
        'telegram' => TelegramChannel::class,
        'mail' => MailChannel::class,
    ],

];
