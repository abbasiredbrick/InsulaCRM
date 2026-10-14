<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\ColdCallAssignmentService;
use Illuminate\Console\Command;

class AssignColdCalls extends Command
{
    protected $signature = 'coldcalls:assign {--batch=20 : Contacts per agent per run}';

    protected $description = 'Distribute unassigned cold-call contacts to active cold-call agents in round-robin batches.';

    public function handle(ColdCallAssignmentService $service): int
    {
        $batch = max(1, (int) $this->option('batch'));

        foreach (Tenant::all() as $tenant) {
            $result = $service->assignBatch($tenant, $batch);

            $this->info("[{$tenant->name}] assigned {$result['assigned']} contact(s) across {$result['agents']} agent(s).");
        }

        return self::SUCCESS;
    }
}
