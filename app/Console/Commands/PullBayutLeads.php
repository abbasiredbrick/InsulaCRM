<?php

namespace App\Console\Commands;

use App\Models\PortalIntegration;
use App\Services\Portals\BayutLeadsPullService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class PullBayutLeads extends Command
{
    protected $signature = 'portals:pull-bayut-leads {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Pull leads from the Bayut / Dubizzle overall leads API for every configured integration';

    public function handle(): int
    {
        $query = PortalIntegration::withoutGlobalScopes()
            ->where('portal', 'bayut')
            ->whereNotNull('leads_api_token');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $integrations = $query->get();

        if ($integrations->isEmpty()) {
            $this->info('No portal integrations with a leads API token.');

            return self::SUCCESS;
        }

        foreach ($integrations as $integration) {
            try {
                $service = new BayutLeadsPullService($integration);
                $result = $service->pull($integration->leads_last_synced_at);

                $integration->refresh();
                $integration->update([
                    'leads_last_synced_at' => $result['error'] === null ? now() : $integration->leads_last_synced_at,
                    'leads_last_error'     => $result['error'],
                ]);

                $this->info("[#{$integration->tenant_id}] Pulled Bayut leads: {$result['created']} new, {$result['ignored']} existing"
                    . ($result['error'] !== null ? " — {$result['error']}" : ''));
            } catch (\Throwable $e) {
                Log::error('Bayut leads pull failed', ['integration_id' => $integration->id, 'error' => $e->getMessage()]);
                $integration->update(['leads_last_error' => Str::limit($e->getMessage(), 500)]);
                $this->error("[#{$integration->tenant_id}] Pull failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}