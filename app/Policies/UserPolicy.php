<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Team-management authority is strictly hierarchical within a tenant:
     * the Owner manages Admins and everyone below, an Admin manages only the
     * operational roles below them. Nobody manages their own account here and
     * no one can act on a peer or a superior (so Admins can never touch the
     * Owner, and the Owner is only changed through ownership transfer).
     */
    public function manageTeamMember(User $user, User $target): bool
    {
        if ($user->tenant_id !== $target->tenant_id) {
            return false;
        }

        if ($user->id === $target->id) {
            return false;
        }

        return $user->isAdmin() && $user->outranks($target);
    }
}
