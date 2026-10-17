<?php

namespace App\Policies;

use App\Enums\ProjectRole;
use App\Models\AlertChannel;
use App\Models\Project;
use App\Models\User;

class AlertChannelPolicy
{
    public function before(User $user): ?bool
    {
        return $user->is_super_admin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, AlertChannel $channel): bool
    {
        return $user->hasRoleAtLeast($channel->project, ProjectRole::Admin);
    }

    public function create(User $user, Project $project): bool
    {
        return $user->hasRoleAtLeast($project, ProjectRole::Admin);
    }

    public function update(User $user, AlertChannel $channel): bool
    {
        return $user->hasRoleAtLeast($channel->project, ProjectRole::Admin);
    }

    public function delete(User $user, AlertChannel $channel): bool
    {
        return $user->hasRoleAtLeast($channel->project, ProjectRole::Admin);
    }
}
