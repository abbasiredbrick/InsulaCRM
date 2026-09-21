<?php

namespace App\Services;

use App\Events\ActivityLogged;
use App\Facades\Hooks;
use App\Models\Activity;
use App\Models\Lead;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * Centralises the logging of scheduling activity (viewings / meetings / tasks)
 * onto a lead's activity log, so that every schedule item shows in the same
 * timeline as calls, SMS and emails.
 */
class ScheduleActivityService
{
    public function __construct(protected TeamNotifier $teamNotifier) {}

    /**
     * Log a schedule event against a lead with a typed, subject-linked activity.
     */
    public function log(
        Lead $lead,
        string $type,
        string $subject,
        ?string $body = null,
        ?Model $entity = null,
        ?User $actor = null,
        ?Carbon $loggedAt = null,
    ): Activity {
        $actor ??= auth()->user();

        $activity = Activity::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $actor?->id,
            'type' => $type,
            'subject' => $subject,
            'body' => $body,
            'subject_type' => $entity ? $entity::class : null,
            'subject_id' => $entity?->getKey(),
            'logged_at' => $loggedAt ?? now(),
        ]);

        app(MotivationScoreService::class)->recalculate($lead);
        event(new ActivityLogged($activity));
        Hooks::doAction('activity.logged', $activity);

        return $activity;
    }

    /**
     * Log feedback on a schedule item: same as log() but also fans the
     * schedule-feedback notifications out to the assigned agent and managers.
     */
    public function logFeedback(
        Lead $lead,
        string $type,
        string $subject,
        string $body,
        Model $entity,
        ?User $actor = null,
    ): Activity {
        $activity = $this->log($lead, $type, $subject, $body, $entity, $actor);

        $this->teamNotifier->notifyScheduleFeedback($activity, $entity, "{$subject}: {$body}");

        return $activity;
    }
}
