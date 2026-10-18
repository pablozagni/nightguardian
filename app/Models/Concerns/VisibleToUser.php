<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Restricts a project-owned model to the projects a user is a member of (super admins see everything).
 */
trait VisibleToUser
{
    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $ids = $user->visibleProjectIds();

        return $ids === null ? $query : $query->whereIn($this->qualifyColumn('project_id'), $ids);
    }
}
