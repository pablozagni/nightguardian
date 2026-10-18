<?php

namespace App\Alerts\Channels;

use App\Alerts\AlertMessage;
use Filament\Schemas\Components\Component;

/**
 * A destination for alerts (Telegram, e-mail, …). Register new ones in config/nightguardian.php → alert_channels.
 */
interface AlertChannel
{
    /** Stored in `alert_channels.type`, e.g. `telegram`. */
    public static function type(): string;

    /** Translated name shown in the panel. */
    public static function label(): string;

    /**
     * Filament components that edit this channel's config. Their names are the config keys
     * (they are placed under the `config` state path, which is encrypted at rest).
     *
     * @return list<Component>
     */
    public static function configForm(): array;

    /**
     * Throws when the config is not valid for this channel (used before saving and before sending).
     *
     * @param  array<string, mixed>  $config
     * @return list<string> human readable problems, empty when valid
     */
    public static function validate(array $config): array;

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws \Throwable when the message could not be delivered; the message of the exception is shown in the panel
     */
    public function send(AlertMessage $message, array $config): void;

    /**
     * The "Send test" button.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws \Throwable
     */
    public function test(array $config): void;
}
