<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Services\LeadReferenceService;
use Illuminate\Console\Command;

class GenerateLeadReferences extends Command
{
    protected $signature = 'leads:generate-references {--dry-run : Preview references without persisting} {--limit=200 : Max leads to process per run}';

    protected $description = 'Assign a human-readable reference to leads that are missing one';

    public function handle(): int
    {
        $service = app(LeadReferenceService::class);
        $limit = (int) $this->option('limit');

        $leads = Lead::withoutGlobalScopes()
            ->whereNull('reference')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($leads->isEmpty()) {
            $this->info('All leads already have a reference.');

            return self::SUCCESS;
        }

        $count = 0;
        foreach ($leads as $lead) {
            $reference = $service->generate($lead);
            if (! $this->option('dry-run')) {
                $lead->forceFill(['reference' => $reference])->saveQuietly();
            }
            $this->line("{$lead->id}: {$lead->first_name} {$lead->last_name} → {$reference}");
            $count++;
        }

        $this->info("Done. " . ($this->option('dry-run') ? 'Would update ' : 'Updated ') . "{$count} leads.");

        return self::SUCCESS;
    }
}