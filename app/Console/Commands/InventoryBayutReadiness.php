<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Portals\BayutListingValidator;
use Illuminate\Console\Command;

class InventoryBayutReadiness extends Command
{
    protected $signature = 'inventory:bayut-readiness {--tenant= : Restrict to a single tenant ID} {--json : Machine-readable JSON output}';

    protected $description = 'Report which inventory units are ready to publish to Bayut and which mandatory fields are missing';

    public function handle(): int
    {
        $query = Tenant::withoutGlobalScopes()->orderBy('id');

        if ($tenantId = $this->option('tenant')) {
            $query->where('id', (int) $tenantId);
        }

        $tenants = $query->get();

        if ($tenants->isEmpty()) {
            $this->error('No tenants found.');

            return self::FAILURE;
        }

        $json = [];

        foreach ($tenants as $tenant) {
            $report = app(BayutListingValidator::class)->report($tenant);
            $json[] = $report + ['tenant_id' => $tenant->id];

            $this->info(sprintf(
                'Tenant #%d (%s): %d unit(s) in portal inventory — %d ready, %d blocked.',
                $tenant->id,
                $tenant->name,
                $report['total'],
                $report['ready'],
                $report['blocked']
            ));

            if (! $this->option('json') && $report['blocked'] > 0) {
                $this->table(
                    ['Unit', 'Availability', 'Missing'],
                    collect($report['rows'])
                        ->filter(fn ($r) => count($r['missing']) > 0)
                        ->map(fn ($r) => [
                            $r['property']->display_name,
                            $r['property']->availability,
                            implode(', ', array_column($r['missing'], 'label')),
                        ])
                        ->all()
                );
            }
        }

        if ($this->option('json')) {
            $this->line(json_encode(['tenants' => $json]));
        }

        return self::SUCCESS;
    }
}