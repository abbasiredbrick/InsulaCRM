<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\Task;
use App\Services\Cloud\CloudCalendarService;
use Illuminate\Console\Command;

class CreateMoveInReminders extends Command
{
    protected $signature = 'leads:create-move-in-reminders {--days=14 : Days before the expected move-in to create the reminder}';

    protected $description = 'Create follow-up tasks reminding agents when a client is about to move in';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('days'));

        $leads = Lead::withoutGlobalScopes()
            ->whereNotNull('expected_move_in_date')
            ->whereBetween('expected_move_in_date', [
                now()->startOfDay(),
                now()->startOfDay()->addDays($window),
            ])
            ->with('tenant')
            ->get();

        $created = 0;
        $skipped = 0;

        foreach ($leads as $lead) {
            $existing = Task::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)
                ->where('lead_id', $lead->id)
                ->where('title', 'Move-in reminder')
                ->where('is_completed', false)
                ->exists();

            if ($existing) {
                $skipped++;

                continue;
            }

            $task = Task::create([
                'tenant_id' => $lead->tenant_id,
                'lead_id' => $lead->id,
                'agent_id' => $lead->agent_id ?? $lead->tenant?->users()?->where('is_active', true)?->value('id'),
                'title' => 'Move-in reminder',
                'due_date' => $lead->expected_move_in_date->format('Y-m-d'),
            ]);

            app(CloudCalendarService::class)->sync($task, $task->agent);

            $created++;
            $this->line("Lead #{$lead->id} ({$lead->full_name}) move-in on {$lead->expected_move_in_date->format('M j, Y')} — reminder task created.");
        }

        $this->info("Checked {$leads->count()} lead(s) with upcoming move-in dates, created {$created} reminder(s), skipped {$skipped} existing.");

        return Command::SUCCESS;
    }
}
