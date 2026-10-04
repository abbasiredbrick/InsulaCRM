<?php

namespace App\Console\Commands;

use App\Models\CalendarEventLink;
use App\Models\Meeting;
use App\Models\Showing;
use App\Models\Task;
use App\Services\Cloud\CloudCalendarService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class ReconcileCalendarEvents extends Command
{
    protected $signature = 'calendar:reconcile-events
        {--dry-run : Report the records that would be repaired without contacting the provider}
        {--tenant= : Limit to one tenant id}
        {--limit=0 : Stop after this many repairs (0 = no limit)}';

    protected $description = 'Re-push calendar events for viewings, meetings and tasks whose external event is missing.';

    public function handle(CloudCalendarService $calendar): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant');
        $limit = (int) $this->option('limit');

        $scanned = 0;
        $repaired = 0;
        $failed = 0;

        foreach ($this->recordsNeedingRepair($tenantId) as $record) {
            if ($limit > 0 && $repaired >= $limit) {
                break;
            }

            $scanned++;
            $label = $this->label($record);

            if ($dryRun) {
                $this->line("would re-sync {$label} ({$this->describe($record)})");

                continue;
            }

            $result = $calendar->sync($record);

            if ($result->hasFailures()) {
                $failed++;
                $this->warn("failed  {$label}: ".$result->failureMessage());

                continue;
            }

            $repaired++;
            $this->info("repaired {$label} ({$this->describe($record)})");
        }

        $this->newLine();
        $this->info($dryRun
            ? "Dry run: {$scanned} record(s) would be re-synced."
            : "Scanned {$scanned}, repaired {$repaired}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Records whose sync targets are missing a calendar_event_link. Only
     * records that should currently hold an event are considered — a cancelled
     * viewing must stay withdrawn, not be resurrected.
     *
     * @return \Generator<int, Model>
     */
    protected function recordsNeedingRepair(mixed $tenantId): \Generator
    {
        $queries = [
            Showing::query()->where('status', 'scheduled'),
            Meeting::query()->where('status', 'scheduled'),
            Task::query()->whereNotIn('status', ['completed', 'cancelled']),
        ];

        foreach ($queries as $query) {
            $query->withoutGlobalScopes()->orderBy('id');

            if ($tenantId !== null && $tenantId !== '') {
                $query->where('tenant_id', (int) $tenantId);
            }

            foreach ($query->cursor() as $record) {
                if ($this->isMissingLinks($record)) {
                    yield $record;
                }
            }
        }
    }

    /**
     * True when at least one involved user has a calendar connection but no
     * tracked event for this record.
     */
    protected function isMissingLinks(Model $record): bool
    {
        $existing = CalendarEventLink::withoutGlobalScopes()
            ->where('eventable_type', $record::class)
            ->where('eventable_id', $record->getKey())
            ->pluck('user_id')
            ->all();

        $expected = DB::table('user_cloud_connections')
            ->where('scope', 'calendar')
            ->when(
                $record->getAttribute('tenant_id') !== null,
                fn ($q) => $q->where('tenant_id', $record->getAttribute('tenant_id'))
            )
            ->distinct()
            ->pluck('user_id')
            ->all();

        // Any connected user involved in the record but without a link means the
        // event is genuinely missing. Users with no connection are not a fault.
        foreach ($this->involvedUserIds($record) as $userId) {
            if (in_array((int) $userId, $expected, true) && ! in_array((int) $userId, $existing, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    protected function involvedUserIds(Model $record): array
    {
        $ids = [];

        foreach (['agent_id', 'created_by'] as $column) {
            $value = $record->getAttribute($column);
            if ($value) {
                $ids[] = (int) $value;
            }
        }

        if ($record instanceof Showing && $record->lead_id) {
            $ownerId = DB::table('leads')
                ->where('id', $record->lead_id)
                ->value('agent_id');

            if ($ownerId) {
                $ids[] = (int) $ownerId;
            }
        }

        return array_values(array_unique($ids));
    }

    protected function label(Model $record): string
    {
        $class = class_basename($record);

        return $record->lead_id
            ? "{$class} #{$record->getKey()} (lead #{$record->lead_id})"
            : "{$class} #{$record->getKey()}";
    }

    protected function describe(Model $record): string
    {
        if ($record instanceof Showing) {
            return $record->showing_date?->format('Y-m-d').' '.(string) $record->showing_time;
        }

        if ($record instanceof Meeting) {
            return (string) $record->scheduled_at;
        }

        if ($record instanceof Task) {
            return (string) $record->due_date.' '.(string) $record->due_time;
        }

        return '';
    }
}
