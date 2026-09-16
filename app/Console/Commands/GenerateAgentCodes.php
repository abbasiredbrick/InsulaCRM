<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AgentCodeService;
use Illuminate\Console\Command;

class GenerateAgentCodes extends Command
{
    protected $signature = 'agents:generate-codes {--dry-run : Preview assignments without persisting} {--reset : Recompute every agent code (backfill the current formula)}';

    protected $description = 'Assign unique agent codes to users that do not yet have one (or backfill all with --reset)';

    public function handle(): int
    {
        $service = app(AgentCodeService::class);
        $users = User::query()
            ->when(! $this->option('reset'), fn ($q) => $q->whereNull('agent_code'))
            ->where('name', '!=', '')
            ->orderBy('id')
            ->get();

        if ($users->isEmpty()) {
            $this->info('All users already have an agent code.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($users as $user) {
            $code = $service->generate((string) $user->name);
            if (! $this->option('dry-run')) {
                $user->forceFill(['agent_code' => $code])->saveQuietly();
            }
            $this->line("{$user->id}: {$user->name} → {$code}");
            $count++;
        }

        $this->info("Done. " . ($this->option('dry-run') ? 'Would update ' : 'Updated ') . "{$count} users.");

        return self::SUCCESS;
    }
}