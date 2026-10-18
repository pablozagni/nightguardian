<?php

namespace App\Policies;

use App\Models\User;

/**
 * User management is for super admins only.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function view(User $user, User $model): bool
    {
        return $user->is_super_admin;
    }

    public function create(User $user): bool
    {
        return $user->is_super_admin;
    }

    public function update(User $user, User $model): bool
    {
        return $user->is_super_admin;
    }

    /** Nobody deletes their own account from the panel. */
    public function delete(User $user, User $model): bool
    {
        return $user->is_super_admin && ! $user->is($model);
    }
}
