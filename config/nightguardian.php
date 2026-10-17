<?php

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
        // 'telegram' => App\Alerts\Channels\TelegramChannel::class,
        // 'mail' => App\Alerts\Channels\MailChannel::class,
    ],

];
