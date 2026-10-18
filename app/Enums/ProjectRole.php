<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ProjectRole: string implements HasColor, HasLabel
{
    case Admin = 'admin';
    case Member = 'member';
    case Viewer = 'viewer';

    /**
     * Roles are cumulative: admin > member > viewer.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Admin => 3,
            self::Member => 2,
            self::Viewer => 1,
        };
    }

    public function atLeast(self $role): bool
    {
        return $this->rank() >= $role->rank();
    }

    public function getLabel(): string
    {
        return __('nightguardian.roles.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Member => 'primary',
            self::Viewer => 'gray',
        };
    }
}
