<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Issue;
use App\Models\User;

class IssuePolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_super_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Issue $issue): bool
    {
        return $user->hasRoleAtLeast($issue->project, ProjectRole::Viewer);
    }

    /** Resolve, ignore, merge, comment. */
    public function update(User $user, Issue $issue): bool
    {
        return $user->hasRoleAtLeast($issue->project, ProjectRole::Member);
    }

    public function delete(User $user, Issue $issue): bool
    {
        return $user->hasRoleAtLeast($issue->project, ProjectRole::Admin);
    }
}
