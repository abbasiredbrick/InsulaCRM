<?php

namespace App\Console\Commands;

use App\Models\PortalIntegration;
use App\Services\Portals\PortalLeadSyncService;
use Illuminate\Console\Command;

class PullBayutLeads extends Command
{
    protected $signature = 'portals:pull-bayut-leads {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Pull leads from the Bayut / Dubizzle overall leads API for every configured integration';

    public function handle(PortalLeadSyncService $sync): int
    {
        $tenantId = $this->option('tenant');

        // Bayut pulls with leads_api_token, not the main api_token pair - the
        // service owns that distinction, along with is_active, the lock and the
        // cursor rules, so this command cannot drift from the manual button.
        $integrations = $sync->pullable()
            ->filter(fn (PortalIntegration $i) => $i->portal === 'bayut')
            ->when($tenantId, fn ($c) => $c->filter(fn ($i) => $i->tenant_id === (int) $tenantId))
            ->values();

        if ($integrations->isEmpty()) {
            $this->info('No portal integrations with a leads API token.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            // force: the schedule is the primary trigger, so it always pulls.
            $result = $sync->pull($integration, force: true);

            if ($result['skipped']) {
                continue;
            }

            $line = "[#{$integration->tenant_id}] Pulled Bayut leads: {$result['created']} new, {$result['ignored']} existing";

            if ($result['error'] !== null) {
                $this->error($line.' — '.$result['error']);

                continue;
            }

            $this->info($line);
        }

        return self::SUCCESS;
    }
}
