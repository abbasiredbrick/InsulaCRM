<?php

namespace App\Console\Commands;

use App\Models\PortalIntegration;
use App\Services\Portals\BayutStatusSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PortalsSyncListingStatus extends Command
{
    protected $signature = 'portals:sync-listing-status {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Synchronise the live/removed status of listed units against the Bayut push API';

    public function handle(): int
    {
        $query = PortalIntegration::withoutGlobalScopes()
            ->where('portal', 'bayut')
            ->where('is_active', true);

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No active Bayut portal integrations to sync.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            try {
                $result = (new BayutStatusSyncService($integration))->sync();

                $integration->forceFill([
                    'last_synced_at' => now(),
                    'last_error'     => $result['error'],
                ])->save();

                $this->info(sprintf(
                    '[#%d] Bayut status sync: %d checked, %d live, %d status changes, %d removed%s',
                    $integration->tenant_id,
                    $result['checked'],
                    $result['live'],
                    $result['updated'],
                    $result['removed'],
                    $result['error'] !== null ? ' - ' . $result['error'] : ''
                ));
            } catch (\Throwable $e) {
                Log::error('Bayut listing status sync failed', ['integration_id' => $integration->id, 'error' => $e->getMessage()]);
                $integration->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();
                $this->error("[#{$integration->tenant_id}] Sync failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}