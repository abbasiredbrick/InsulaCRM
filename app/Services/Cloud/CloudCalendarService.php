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
     * The calendar connection to write events to for a record.
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
     * Create/update/delete the external calendar event for a record. Best
     * effort – never fails the underlying business request.
     */
    public function sync(Model $record, ?User $actingUser = null): void
    {
        if (! $this->integrationEnabled($record, $actingUser)) {
            return;
        }

        $connection = $this->connectionFor($record, $actingUser);

        if ($this->shouldRemove($record)) {
            $this->removeEvent($record);

            return;
        }

        if (! $connection) {
            return;
        }

        try {
            $provider = $this->factory->make($connection->provider, $connection);
            $payload = $this->buildEvent($record);

            if (! blank($record->calendar_event_id)) {
                $provider->updateCalendarEvent((string) $record->calendar_event_id, $payload);

                return;
            }

            $eventId = $provider->createCalendarEvent($payload);
            $record->forceFill([
                'calendar_provider' => $connection->provider,
                'calendar_event_id' => $eventId,
            ])->save();
        } catch (\Throwable $e) {
            Log::warning('CloudCalendarService sync failed', [
                'record' => get_class($record),
                'record_id' => $record->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remove a stale external event (cancelled/completed records).
     */
    public function removeEvent(Model $record): void
    {
        if (! $this->integrationEnabled($record)) {
            return;
        }

        if (blank($record->calendar_event_id)) {
            return;
        }

        $connection = $record->calendar_provider && $record->getAttribute('agent_id')
            ? UserCloudConnection::where('user_id', $record->getAttribute('agent_id'))
                ->where('provider', $record->calendar_provider)
                ->where('scope', 'calendar')
                ->first()
            : null;

        if ($connection) {
            try {
                $this->factory->make($connection->provider, $connection)
                    ->deleteCalendarEvent((string) $record->calendar_event_id);
            } catch (\Throwable $e) {
                Log::warning('CloudCalendarService delete failed', ['error' => $e->getMessage()]);
            }
        }

        $record->forceFill(['calendar_provider' => null, 'calendar_event_id' => null])->save();
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
        $tz = config('app.timezone');

        if ($record instanceof Showing) {
            $allDay = blank($record->showing_time) || ! $this->isParseableTime($record->showing_time);
            $start = Carbon::parse($record->showing_date->format('Y-m-d').($allDay ? '' : ' '.$record->showing_time), $tz);
            $start->setTimezone($tz);

            $lines = [];
            if ($record->lead) {
                $lines[] = __('Client').': '.$record->lead->full_name;
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
            $start = Carbon::parse($record->due_date->format('Y-m-d'), $tz);

            return [
                'summary' => __('Follow-up').': '.$record->title,
                'description' => $record->lead ? __('Client').': '.$record->lead->full_name : '',
                'start' => $start,
                'all_day' => true,
                'duration_minutes' => 0,
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
