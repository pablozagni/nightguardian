<?php

namespace App\Enums;

enum ProjectRole: string
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
}
