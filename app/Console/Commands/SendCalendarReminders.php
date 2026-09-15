<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Models\OpenHouse;
use App\Models\Showing;
use App\Models\Task;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\CalendarReminderNotification;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;

class SendCalendarReminders extends Command
{
    protected $signature = 'calendar:send-reminders {--lookahead=7 : Days ahead to consider upcoming schedules}';

    protected $description = 'Send in-app and email reminders for upcoming viewings, meetings, tasks and open houses when they are not handled by an external calendar';

    public function handle(): int
    {
        $sent = 0;
        $external = 0;
        $records = $this->dueRecords();

        foreach ($records as $record) {
            $tenant = Tenant::withoutGlobalScopes()->find($record->tenant_id);

            if (! $tenant || ! $tenant->wantsNotification('calendar_reminders')) {
                continue;
            }

            $minutes = $record->reminder_minutes ?? $tenant->calendarReminderDefaultMinutes();
            $start = $this->recordStart($record);

            if ($minutes === null || $start->isPast() || $start->greaterThan($this->lookahead())) {
                continue;
            }

            if (now()->lessThan($start->copy()->subMinutes($minutes))) {
                continue;
            }

            if ($this->managedExternally($record, $tenant)) {
                $external++;
                $record->forceFill(['reminder_sent_at' => now()])->save();

                continue;
            }

            $agent = $record->agent_id ? User::withoutGlobalScopes()->find($record->agent_id) : null;
            if (! $agent || ! $agent->email) {
                continue;
            }

            Notification::send($agent, new CalendarReminderNotification($record, $tenant));

            $record->forceFill(['reminder_sent_at' => now()])->save();
            $sent++;

            $this->line(sprintf(
                'Reminder sent to %s for %s #%d (%s)',
                $agent->email,
                class_basename($record),
                $record->getKey(),
                $start->format('M j, Y H:i'),
            ));
        }

        $this->info(sprintf('Calendar reminders processed: %s sent, %s skipped (external calendar), %d records considered.', $sent, $external, $records->count()));

        return Command::SUCCESS;
    }

    protected function dueRecords(): Collection
    {
        $from = now()->subDay();
        $to = $this->lookahead();
        $dayTo = $to->format('Y-m-d');

        $records = collect();

        $records = $records->merge(
            Showing::withoutGlobalScopes()
                ->with(['property', 'lead'])
                ->where('status', 'scheduled')
                ->whereNull('reminder_sent_at')
                ->whereBetween('showing_date', [$from->format('Y-m-d'), $dayTo])
                ->get()
        );

        $records = $records->merge(
            Meeting::withoutGlobalScopes()
                ->with(['lead'])
                ->where('status', 'scheduled')
                ->whereNull('reminder_sent_at')
                ->where('scheduled_at', '>=', $from->toDateTimeString())
                ->where('scheduled_at', '<=', $to->toDateTimeString())
                ->get()
        );

        $records = $records->merge(
            Task::withoutGlobalScopes()
                ->with(['lead'])
                ->where('is_completed', false)
                ->whereNull('reminder_sent_at')
                ->whereBetween('due_date', [$from->format('Y-m-d'), $dayTo])
                ->get()
        );

        $records = $records->merge(
            OpenHouse::withoutGlobalScopes()
                ->with(['property'])
                ->whereIn('status', ['scheduled', 'active'])
                ->whereNull('reminder_sent_at')
                ->whereBetween('event_date', [$from->format('Y-m-d'), $dayTo])
                ->get()
        );

        return $records;
    }

    protected function recordStart(Model $record): CarbonInterface
    {
        if ($record instanceof Showing || $record instanceof OpenHouse) {
            $date = $record->showing_date ?? $record->event_date;
            $time = $record->showing_time ?? $record->start_time;

            return $date->copy()->setTimeFrom($this->parseTime($time));
        }

        if ($record instanceof Task) {
            return $record->due_date->copy()->startOfDay();
        }

        return $record->scheduled_at;
    }

    protected function managedExternally(Model $record, Tenant $tenant): bool
    {
        if (! $tenant->calendarSyncEnabled()) {
            return false;
        }

        return ! blank($record->calendar_provider);
    }

    protected function parseTime(mixed $value): CarbonInterface
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return \Carbon\Carbon::parse($value);
            } catch (\Throwable) {
            }
        }

        return \Carbon\Carbon::parse('09:00');
    }

    protected function lookahead(): \Carbon\Carbon
    {
        return now()->copy()->addDays(max(1, (int) $this->option('lookahead')));
    }
}
