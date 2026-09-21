<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use App\Notifications\LeadReassigned;
use App\Notifications\ScheduleFeedbackNotification;
use App\Notifications\TeamLeadActivity;
use Illuminate\Support\Facades\Log;

/**
 * Fires the manager-facing notifications that let managers follow up on their
 * team's leads: every activity logged on a team lead, and lead reassignments.
 *
 * All notifications are synchronous (no queue) because the production server
 * has no queue worker.
 */
class TeamNotifier
{
    /**
     * Notify the managers in the chain of everyone who will be subscribed:
     * the agent's managers (activity) or the target agent's managers (reassign).
     */
    public function notifyActivityLogged(Activity $activity): void
    {
        if (! $activity->lead_id || ! $activity->agent_id) {
            return;
        }

        $lead = $activity->lead;

        if (! $lead || ! $lead->tenant || ! $lead->tenant->wantsNotification('team_activity')) {
            return;
        }

        $agent = $lead->agent;
        if (! $agent) {
            return;
        }

        foreach ($this->managersOf($agent, $activity->agent_id) as $manager) {
            try {
                $manager->notify(new TeamLeadActivity($lead, $activity, $agent));
            } catch (\Throwable $e) {
                Log::error("TeamNotifier activity failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Notify everyone involved in a reassignment: the newly assigned agent, the
     * previous agent (if any) and the managers above the new agent.
     */
    public function notifyLeadReassigned(Lead $lead, ?User $from, User $to, ?string $reason): void
    {
        $tenant = $lead->tenant;
        if (! $tenant) {
            return;
        }

        $enabled = $tenant->wantsNotification('lead_reassigned') || $tenant->wantsNotification('team_reassigned');
        if (! $enabled) {
            return;
        }

        if ($to->id !== auth()->id() && $tenant->wantsNotification('lead_reassigned')) {
            try {
                $to->notify(new LeadReassigned($lead, $from, $to, $reason));
            } catch (\Throwable $e) {
                Log::error("TeamNotifier new-agent failed: {$e->getMessage()}");
            }
        }

        if ($from && $from->id !== $to->id && $from->id !== auth()->id()
            && $tenant->wantsNotification('lead_reassigned')) {
            try {
                $from->notify(new LeadReassigned($lead, $from, $to, $reason));
            } catch (\Throwable $e) {
                Log::error("TeamNotifier old-agent failed: {$e->getMessage()}");
            }
        }

        if ($tenant->wantsNotification('team_reassigned')) {
            foreach ($this->managersOf($to) as $manager) {
                try {
                    $manager->notify(new LeadReassigned($lead, $from, $to, $reason));
                } catch (\Throwable $e) {
                    Log::error("TeamNotifier manager failed: {$e->getMessage()}");
                }
            }
        }
    }

    /**
     * Notify a newly added co-agent that they now share this lead.
     */
    public function notifyCoAgentAdded(Lead $lead, User $coAgent): void
    {
        $tenant = $lead->tenant;
        if ($tenant && $coAgent->id !== auth()->id() && $tenant->wantsNotification('lead_reassigned')) {
            try {
                $coAgent->notify(new LeadReassigned($lead, null, $lead->agent, __('A colleague added you as a co-agent on this lead.')));
            } catch (\Throwable $e) {
                Log::error("TeamNotifier co-agent failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Notify the agent assigned to a viewing/task/meeting and the managers in
     * that agent's reporting chain when feedback is logged on the schedule, so
     * they can follow up with the client.
     */
    public function notifyScheduleFeedback(Activity $activity, object $entity, string $summary): void
    {
        if (! $activity->lead_id) {
            return;
        }

        $lead = $activity->lead;

        if (! $lead || ! $lead->tenant || ! $lead->tenant->wantsNotification('schedule_feedback')) {
            return;
        }

        $agent = $entity->agent;
        if (! $agent) {
            return;
        }

        $recipients = [];

        if ($agent->id !== $activity->agent_id) {
            $recipients[$agent->id] = $agent;
        }

        foreach ($this->managersOf($agent, $activity->agent_id) as $manager) {
            $recipients[$manager->id] = $manager;
        }

        foreach ($recipients as $recipient) {
            try {
                $recipient->notify(new ScheduleFeedbackNotification($activity, $lead, $summary));
            } catch (\Throwable $e) {
                Log::error("TeamNotifier schedule feedback failed: {$e->getMessage()}");
            }
        }
    }

    /**
     * Every manager (someone with at least one report) above the agent, within
     * the same tenant, excluding the current actor.
     */
    protected function managersOf(User $agent, ?int $excludeId = null): array
    {
        $managers = [];
        $chain = $agent->managerChain();

        foreach ($chain as $manager) {
            if ($manager->id === auth()->id()) {
                continue;
            }
            if ($manager->id === $excludeId) {
                continue;
            }
            if ($manager->isManager()) {
                $managers[$manager->id] = $manager;
            }
        }

        return array_values($managers);
    }
}