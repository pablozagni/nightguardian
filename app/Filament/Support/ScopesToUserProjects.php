<?php

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Restricts a panel query to the projects a user belongs to (super admins see all; guests see nothing).
 */
final class ScopesToUserProjects
{
    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function restrict(Builder $query, ?Authenticatable $user, string $column = 'project_id'): Builder
    {
        if (! $user instanceof User) {
            return $query->whereRaw('1 = 0');
        }

        $ids = $user->visibleProjectIds();

        return $ids === null ? $query : $query->whereIn($query->qualifyColumn($column), $ids);
    }
}
