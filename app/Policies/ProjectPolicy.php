<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_super_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Project $project): bool
    {
        return $user->hasRoleAtLeast($project, ProjectRole::Viewer);
    }

    /** Only super admins create projects (handled by before()). */
    public function create(User $user): bool
    {
        return false;
    }

    /** Settings, members. */
    public function update(User $user, Project $project): bool
    {
        return $user->hasRoleAtLeast($project, ProjectRole::Admin);
    }

    public function delete(User $user, Project $project): bool
    {
        return false;
    }
}
