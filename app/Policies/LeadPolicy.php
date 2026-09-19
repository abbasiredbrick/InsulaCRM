<?php

namespace App\Policies;

use App\Models\Lead;
use App\Models\User;

class LeadPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canManageLeads();
    }

    public function view(User $user, Lead $lead): bool
    {
        return $this->ownsOrCanManage($user, $lead);
    }

    public function create(User $user): bool
    {
        return $user->canManageLeads();
    }

    public function update(User $user, Lead $lead): bool
    {
        return $this->ownsOrCanManage($user, $lead);
    }

    public function delete(User $user, Lead $lead): bool
    {
        if (! $user->canManageLeads()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($lead->agent_id === $user->id) {
            return true;
        }

        // Managers (anyone with reports) can delete their team's leads, but a
        // co-agent never can - they only share access, not ownership.
        return $lead->agent_id !== null && in_array($lead->agent_id, $user->teamUserIds(), true);
    }

    public function export(User $user): bool
    {
        return $user->canManageLeads();
    }

    public function bulkUpdate(User $user): bool
    {
        return $user->canManageLeads();
    }

    public function claim(User $user, Lead $lead): bool
    {
        return $this->ownsOrCanManage($user, $lead) || $lead->agent_id === null;
    }

    /**
     * A manager or admin may move a lead to another team member.
     */
    public function reassign(User $user, Lead $lead): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if (! $user->isManager()) {
            return false;
        }

        // Managers can reassign their own leads or any lead within their team.
        return $lead->agent_id === null
            || $lead->agent_id === $user->id
            || in_array($lead->agent_id, $user->teamUserIds(), true);
    }

    /**
     * The owner, admins and managers may add/remove co-agents on a lead.
     */
    public function shareAgents(User $user, Lead $lead): bool
    {
        return $this->ownsOrCanManage($user, $lead);
    }

    private function ownsOrCanManage(User $user, Lead $lead): bool
    {
        if (! $user->canManageLeads()) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($lead->agent_id === $user->id) {
            return true;
        }

        // A co-agent added via the sharing UI has the same access as the owner:
        // follow-ups, tasks, meetings, activities, viewings and status.
        if ($lead->hasCoAgent($user)) {
            return true;
        }

        // Managers (anyone with reports) can see and act on their team's leads.
        return $lead->agent_id !== null && in_array($lead->agent_id, $user->teamUserIds(), true);
    }
}
