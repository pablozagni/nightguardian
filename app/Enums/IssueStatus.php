<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IssueStatus: string implements HasColor, HasLabel
{
    case New = 'new';
    case Regressed = 'regressed';
    case Resolved = 'resolved';
    case Ignored = 'ignored';

    public function getLabel(): string
    {
        return __('nightguardian.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::New => 'info',
            self::Regressed => 'danger',
            self::Resolved => 'success',
            self::Ignored => 'gray',
        };
    }
}
