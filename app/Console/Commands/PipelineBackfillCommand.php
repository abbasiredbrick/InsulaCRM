<?php

namespace App\Console\Commands;

use App\Models\Deal;
use App\Models\Lead;
use App\Services\PipelineSyncService;
use Illuminate\Console\Command;

class PipelineBackfillCommand extends Command
{
    protected $signature = 'keystone:pipeline-backfill
                            {--dry-run : Report what would be created without writing.}';

    protected $description = 'Backfill Deals for leads that have reached a revenue stage.';

    public function handle(PipelineSyncService $sync): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $leads = Lead::withoutGlobalScopes()
            ->with(['property', 'tenant'])
            ->whereNotNull('stage')
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;
        $already = 0;

        foreach ($leads as $lead) {
            if (! $sync->shouldHaveDeal($lead)) {
                $skipped++;

                continue;
            }

            $existing = Deal::withoutGlobalScopes()
                ->where('tenant_id', $lead->tenant_id)
                ->where('lead_id', $lead->id)
                ->exists();

            if ($existing) {
                $already++;

                continue;
            }

            $stage = $sync->dealStageFor($lead, $lead->tenant);
            if ($stage === null) {
                $skipped++;

                continue;
            }

            $created++;

            if ($dryRun) {
                $this->line("  would create deal for lead #{$lead->id} ({$lead->full_name}) → stage {$stage}");

                continue;
            }

            $sync->syncForLead($lead);
            $this->line("  created deal for lead #{$lead->id} ({$lead->full_name}) → stage {$stage}");
        }

        $this->info("Backfill: created={$created} already={$already} skipped={$skipped}".($dryRun ? ' (dry-run, nothing written)' : ''));

        return self::SUCCESS;
    }
}
