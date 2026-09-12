<?php

namespace App\Policies;

use App\Models\Lease;
use App\Models\User;

class LeasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isAgent() || $user->isListingAgent() || $user->isBuyersAgent();
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, Lease $lease): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Lease $lease): bool
    {
        return $this->ownsOrCanManage($user, $lease);
    }

    public function renew(User $user, Lease $lease): bool
    {
        return $this->ownsOrCanManage($user, $lease);
    }

    private function ownsOrCanManage(User $user, Lease $lease): bool
    {
        if ($user->isAdmin() || $user->isListingAgent() || $user->isBuyersAgent()) {
            return true;
        }

        if ($user->isAgent() && $lease->agent_id === $user->id) {
            return true;
        }

        return $lease->agent_id !== null
            && $user->isManager()
            && in_array($lease->agent_id, $user->teamUserIds(), true);
    }
}
