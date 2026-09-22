<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\LeadRecycleService;
use Illuminate\Console\Command;

class RecycleLeads extends Command
{
    protected $signature = 'recycle:leads {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Move leads stuck in lost/dead/nurture for 60+ days into the Recycled Leads pool';

    public function handle(LeadRecycleService $service): int
    {
        $tenant = null;

        if ($tenantId = $this->option('tenant')) {
            $tenant = Tenant::find((int) $tenantId);

            if (! $tenant) {
                $this->error("Tenant #{$tenantId} not found.");

                return self::FAILURE;
            }
        }

        $result = $service->recycle($tenant);

        foreach ($result['per_tenant'] as $tenantId => $count) {
            $this->line("[#{$tenantId}] recycled {$count} lead(s)");
        }

        $this->info('Total recycled: '.$result['recycled']);

        return self::SUCCESS;
    }
}
