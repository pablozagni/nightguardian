<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * PSR-3 levels, ordered from least to most severe.
 */
enum Level: string implements HasColor, HasLabel
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    public function severity(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Notice => 2,
            self::Warning => 3,
            self::Error => 4,
            self::Critical => 5,
            self::Alert => 6,
            self::Emergency => 7,
        };
    }

    public function atLeast(self $level): bool
    {
        return $this->severity() >= $level->severity();
    }

    public function getLabel(): string
    {
        return __('nightguardian.levels.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Debug, self::Info => 'gray',
            self::Notice => 'info',
            self::Warning => 'warning',
            self::Error, self::Critical, self::Alert, self::Emergency => 'danger',
        };
    }
}
