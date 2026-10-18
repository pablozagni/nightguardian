<?php

namespace App\Alerts;

use App\Alerts\Channels\AlertChannel;
use InvalidArgumentException;

/**
 * The channel types listed in config/nightguardian.php → alert_channels.
 */
class ChannelRegistry
{
    /**
     * @return array<string, class-string<AlertChannel>>
     */
    public function all(): array
    {
        /** @var array<string, class-string<AlertChannel>> $channels */
        $channels = (array) config('nightguardian.alert_channels', []);

        return $channels;
    }

    public function has(string $type): bool
    {
        return isset($this->all()[$type]);
    }

    /**
     * @return class-string<AlertChannel>
     */
    public function class(string $type): string
    {
        return $this->all()[$type] ?? throw new InvalidArgumentException("Unknown alert channel type [{$type}].");
    }

    public function make(string $type): AlertChannel
    {
        return app($this->class($type));
    }

    /**
     * @return array<string, string> type => translated label
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->all() as $type => $class) {
            $options[$type] = $class::label();
        }

        return $options;
    }
}
