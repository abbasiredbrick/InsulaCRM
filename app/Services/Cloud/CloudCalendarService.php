<?php

namespace App\Services\Cloud;

use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserCloudConnection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class CloudCalendarService
{
    public function __construct(protected CloudProviderFactory $factory) {}

    /**
     * Scheduling is never blocked: every viewing, meeting, task and open
     * house lives on the system calendar regardless of external sync, with
     * reminders delivered in-app and by email when the Google/Microsoft
     * integration is disabled or no calendar is connected.
     */
    public function schedulerAllowed(?User $user): bool
    {
        return true;
    }

    /**
     * Whether the tenant's Google/Microsoft calendar integration is active.
     * When disabled, events stay on the system calendar and previously pushed
     * external events are left untouched.
     */
    public function integrationEnabled(Model $record, ?User $actingUser = null): bool
    {
        $tenantId = $record->getAttribute('tenant_id') ?? $actingUser?->tenant_id;

        if ($tenantId === null) {
            return true;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($tenantId);

        return $tenant?->calendarSyncEnabled() ?? true;
    }

    /**
     * Whether an event should be pushed to an external calendar right now:
     * the integration must be active and the record's agent (or acting user)
     * must have a connection to write to.
     */
    public function shouldSyncExternally(Model $record, ?User $actingUser = null): bool
    {
        if (! $this->integrationEnabled($record, $actingUser)) {
            return false;
        }

        return $this->connectionFor($record, $actingUser) !== null;
    }

    /**
     * The calendar targets to write events to for a record, keyed by slot.
     *
     * - "assigned": the agent assigned to the record (mandatory). The record
     *   is declined (not pushed) when that agent has no calendar connection.
     * - "main": the lead's owning (main) agent, pushed only when they already
     *   have their own calendar connection *and* differ from the assigned one.
     */
    public function calendarTargets(Model $record, ?User $actingUser = null): array
    {
        $targets = [];

        $assigned = $record->getAttribute('agent_id')
            ? User::withoutGlobalScopes()->find($record->getAttribute('agent_id'))
            : null;

        $assignedConnection = $assigned?->calendarConnections()->latest()->first();

        if ($assignedConnection) {
            $targets['assigned'] = $assignedConnection;
        }

        if (! $record instanceof Showing) {
            return $targets;
        }

        $lead = $record->relationLoaded('lead') ? $record->getRelation('lead') : $record->lead;

        if ($lead && $lead->getAttribute('agent_id')) {
            $mainAgentId = (int) $lead->getAttribute('agent_id');

            if ($mainAgentId !== (int) $record->getAttribute('agent_id')) {
                $main = User::withoutGlobalScopes()->find($mainAgentId);
                $mainConnection = $main?->calendarConnections()->latest()->first();

                if ($mainConnection) {
                    $targets['main'] = $mainConnection;
                }
            }
        }

        return $targets;
    }

    /**
     * Backward-compatible single-connection resolver: the assigned agent's
     * calendar, falling back to the acting user's.
     */
    public function connectionFor(Model $record, ?User $actingUser = null): ?UserCloudConnection
    {
        $agent = $record->getAttribute('agent_id') ? User::withoutGlobalScopes()->find($record->getAttribute('agent_id')) : null;

        $users = array_filter([$agent, $actingUser]);
        foreach ($users as $user) {
            $connection = $user->calendarConnections()->latest()->first();
            if ($connection) {
                return $connection;
            }
        }

        return null;
    }

    /**
     * Create/update/delete the external calendar events for a record. Best
     * effort – never fails the underlying business request.
     *
     * Events are pushed to the assigned agent's calendar (declined when that
     * agent has no connection) and, where possible, the lead's main agent.
     */
    public function sync(Model $record, ?User $actingUser = null): void
    {
        if (! $this->integrationEnabled($record, $actingUser)) {
            return;
        }

        if ($this->shouldRemove($record)) {
            $this->removeEvent($record);

            return;
        }

        $targets = $this->calendarTargets($record, $actingUser);

        if (! isset($targets['assigned'])) {
            return;
        }

        foreach ($targets as $slot => $connection) {
            $this->syncSlot($record, $slot, $connection);
        }
    }

    protected function syncSlot(Model $record, string $slot, UserCloudConnection $connection): void
    {
        $providerColumn = $slot === 'main' ? 'main_calendar_provider' : 'calendar_provider';
        $eventColumn = $slot === 'main' ? 'main_calendar_event_id' : 'calendar_event_id';

        try {
            $provider = $this->factory->make($connection->provider, $connection);
            $payload = $this->buildEvent($record);

            if (! blank($record->getAttribute($eventColumn))) {
                $provider->updateCalendarEvent((string) $record->getAttribute($eventColumn), $payload);

                return;
            }

            $eventId = $provider->createCalendarEvent($payload);
            $record->forceFill([
                $providerColumn => $connection->provider,
                $eventColumn => $eventId,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('CloudCalendarService sync failed', [
                'record' => get_class($record),
                'record_id' => $record->getKey(),
                'slot' => $slot,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove stale external events (cancelled/completed records) from both
     * the assigned and main agent calendars.
     */
    public function removeEvent(Model $record): void
    {
        if (! $this->integrationEnabled($record)) {
            return;
        }

        $slots = [
            ['calendar_provider', 'calendar_event_id'],
            ['main_calendar_provider', 'main_calendar_event_id'],
        ];

        foreach ($slots as [$providerColumn, $eventColumn]) {
            $provider = $record->getAttribute($providerColumn);
            $eventId = $record->getAttribute($eventColumn);

            if (! $provider) {
                continue;
            }

            if (filled($eventId)) {
                $connection = $record->getAttribute('agent_id')
                    ? UserCloudConnection::where('user_id', $record->getAttribute('agent_id'))
                        ->where('provider', $provider)
                        ->where('scope', 'calendar')
                        ->first()
                    : null;

                if ($connection) {
                    try {
                        $this->factory->make($connection->provider, $connection)
                            ->deleteCalendarEvent((string) $eventId);
                    } catch (\Throwable $e) {
                        Log::warning('CloudCalendarService delete failed', ['error' => $e->getMessage()]);
                    }
                }
            }

            $record->forceFill([$providerColumn => null, $eventColumn => null])->save();
        }
    }

    protected function shouldRemove(Model $record): bool
    {
        if ($record instanceof Showing) {
            return $record->status !== 'scheduled';
        }

        if ($record instanceof Task) {
            return (bool) $record->is_completed;
        }

        if ($record instanceof Meeting) {
            return $record->status !== 'scheduled';
        }

        return false;
    }

    protected function buildEvent(Model $record): array
    {
        $tenantId = $record->getAttribute('tenant_id');
        $tz = $tenantId
            ? (Tenant::withoutGlobalScopes()->find($tenantId)?->timezone ?: config('app.timezone'))
            : config('app.timezone');

        if ($record instanceof Showing) {
            $allDay = blank($record->showing_time) || ! $this->isParseableTime($record->showing_time);
            $start = Carbon::parse($record->showing_date->format('Y-m-d').($allDay ? '' : ' '.$record->showing_time), $tz);
            $start->setTimezone($tz);

            $lines = [];
            if ($record->lead) {
                $lines[] = __('Client').': '.$record->lead->full_name;
                if (filled($record->lead->phone)) {
                    $lines[] = __('Mobile').': '.$record->lead->phone;
                }
            }
            if ($record->property) {
                $lines[] = __('Unit').': '.$record->property->optionLabel();
            }
            if ($record->agent) {
                $lines[] = __('Agent').': '.$record->agent->name;
            }
            if ($record->listing_agent_name) {
                $lines[] = __('Listing agent').': '.$record->listing_agent_name.($record->listing_agent_phone ? ' ('.$record->listing_agent_phone.')' : '');
            }
            if ($record->notes) {
                $lines[] = $record->notes;
            }

            $address = $record->property?->address ?? __('Viewing unit #:id', ['id' => $record->property_id]);

            return [
                'summary' => __('Viewing').': '.$address,
                'description' => implode("\n", $lines),
                'start' => $start,
                'all_day' => $allDay,
                'duration_minutes' => (int) ($record->duration_minutes ?: 60),
                'attendee_email' => $record->lead?->email,
                'attendee_name' => $record->lead?->full_name,
            ];
        }

        if ($record instanceof Task) {
            $allDay = blank($record->due_time);
            $start = $allDay
                ? Carbon::parse($record->due_date->format('Y-m-d'), $tz)
                : Carbon::parse($record->due_date->format('Y-m-d').' '.$record->due_time, $tz);

            return [
                'summary' => __('Follow-up').': '.$record->title,
                'description' => $record->lead ? __('Client').': '.$record->lead->full_name : '',
                'start' => $start,
                'all_day' => $allDay,
                'duration_minutes' => $allDay ? 0 : 60,
                'attendee_email' => $record->lead?->email,
                'attendee_name' => $record->lead?->full_name,
            ];
        }

        if ($record instanceof Meeting) {
            $start = $record->scheduled_at->copy()->setTimezone($tz);

            return [
                'summary' => __('Meeting').': '.$record->title,
                'description' => ($record->lead ? __('Client').': '.$record->lead->full_name."\n" : '').trim((string) $record->notes),
                'start' => $start,
                'all_day' => false,
                'duration_minutes' => (int) ($record->duration_minutes ?: 60),
                'attendee_email' => $record->lead?->email,
                'attendee_name' => $record->lead?->full_name,
            ];
        }

        throw new \InvalidArgumentException(__('Unsupported record for calendar sync.'));
    }

    protected function isParseableTime(mixed $value): bool
    {
        if (! is_string($value) || trim($value) === '') {
            return false;
        }

        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
