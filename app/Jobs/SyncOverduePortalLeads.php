<?php

namespace App\Jobs;

use App\Services\Portals\PortalLeadSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Catch-up pull for every active portal integration whose leads have gone
 * stale.
 *
 * The scheduled command in bootstrap/app.php is the primary trigger, but a
 * shared host without a cron entry never runs it - which is exactly the
 * symptom of "I have to click Sync leads to get new leads". This job is the
 * safety net: it is dispatched after the response on a throttled basis from
 * ordinary page views, so leads keep arriving whether or not the scheduler is
 * alive.
 *
 * It only ever runs integrations the service considers overdue, and the
 * service holds a lock, so overlapping triggers are cheap and harmless.
 */
class SyncOverduePortalLeads implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(PortalLeadSyncService $sync): void
    {
        // A pull pages through up to 200 API calls. Shared hosts commonly cap a
        // request at 30s, and a premature kill here would abandon the lock and
        // leave the cursor un-advanced - so lift the cap for the duration.
        set_time_limit(0);

        foreach ($sync->pullable() as $integration) {
            if (! $sync->isDue($integration)) {
                continue;
            }

            $result = $sync->pull($integration);

            if ($result['skipped'] || $result['created'] === 0) {
                continue;
            }

            Log::info('Portal lead catch-up created leads', [
                'portal' => $integration->portal,
                'tenant_id' => $integration->tenant_id,
                'created' => $result['created'],
            ]);
        }
    }
}
