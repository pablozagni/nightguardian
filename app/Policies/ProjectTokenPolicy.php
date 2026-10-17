<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\Project;
use App\Models\ProjectToken;
use App\Models\User;

class ProjectTokenPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_super_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ProjectToken $token): bool
    {
        return $user->hasRoleAtLeast($token->project, ProjectRole::Admin);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRoleAtLeast($project, ProjectRole::Admin);
    }

    /** Revoking a token is an update. */
    public function update(User $user, ProjectToken $token): bool
    {
        return $user->hasRoleAtLeast($token->project, ProjectRole::Admin);
    }

    public function delete(User $user, ProjectToken $token): bool
    {
        return $user->hasRoleAtLeast($token->project, ProjectRole::Admin);
    }
}
