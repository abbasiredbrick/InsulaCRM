<?php

namespace App\Policies;

use App\Models\Showing;
use App\Models\User;

class ShowingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->isAgent() || $user->isListingAgent() || $user->isBuyersAgent();
    }

    public function view(User $user, Showing $showing): bool
    {
        return $this->ownsOrCanManage($user, $showing);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Showing $showing): bool
    {
        return $this->ownsOrCanManage($user, $showing);
    }

    public function delete(User $user, Showing $showing): bool
    {
        return $this->ownsOrCanManage($user, $showing);
    }

    private function ownsOrCanManage(User $user, Showing $showing): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($showing->agent_id === $user->id || $showing->created_by === $user->id) {
            return true;
        }

        if ($showing->lead_id && $showing->lead?->agent_id === $user->id) {
            return true;
        }

        if ($user->isManager()) {
            $teamIds = array_merge([$user->id], $user->teamUserIds());

            return in_array($showing->agent_id, $teamIds, true)
                || in_array($showing->created_by, $teamIds, true)
                || ($showing->lead_id && in_array($showing->lead?->agent_id, $teamIds, true));
        }

        return false;
    }
}
