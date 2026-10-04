<?php

namespace App\Console\Commands;

use App\Models\PortalIntegration;
use App\Services\Portals\PortalLeadSyncService;
use Illuminate\Console\Command;

class PortalsPullPropertyFinderLeads extends Command
{
    protected $signature = 'portals:pull-propertyfinder-leads {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Pull leads from the Property Finder Enterprise leads API for every configured integration';

    public function handle(PortalLeadSyncService $sync): int
    {
        $tenantId = $this->option('tenant');

        // Eligibility (is_active + the credentials this portal actually pulls
        // with) and the whole pull cycle live in the sync service, so this
        // command and a manual "Sync leads" click can never disagree about
        // which integrations are eligible.
        $integrations = $sync->pullable()
            ->filter(fn (PortalIntegration $i) => $i->portal === 'propertyfinder')
            ->when($tenantId, fn ($c) => $c->filter(fn ($i) => $i->tenant_id === (int) $tenantId))
            ->values();

        if ($integrations->isEmpty()) {
            $this->info('No Property Finder integrations configured.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            // force: the schedule *is* the primary trigger, so it always
            // pulls. The overdue check is what throttles the in-app catch-up;
            // letting it gate this too would just skip every other tick.
            $result = $sync->pull($integration, force: true);

            if ($result['skipped']) {
                continue;
            }

            $line = "[#{$integration->tenant_id}] Pulled Property Finder leads: {$result['created']} new, {$result['ignored']} existing";

            if ($result['error'] !== null) {
                $this->error($line.' — '.$result['error']);

                continue;
            }

            $this->info($line);
        }

        return self::SUCCESS;
    }
}
