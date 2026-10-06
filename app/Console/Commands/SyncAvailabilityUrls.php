<?php

namespace App\Console\Commands;

use App\Models\AvailabilityImportRun;
use App\Models\AvailabilitySource;
use App\Services\AvailabilityIngestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncAvailabilityUrls extends Command
{
    protected $signature = 'availability:sync-urls {--tenant= : Restrict to a single tenant ID}';

    protected $description = 'Pull every URL-based availability source (TrueRentor, RDK, CSV feeds) and reconcile inventory';

    public function handle(AvailabilityIngestService $ingest): int
    {
        $query = AvailabilitySource::withoutGlobalScopes()->whereNotNull('url');

        if ($tenantId = $this->option('tenant')) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $sources = $query->get();

        if ($sources->isEmpty()) {
            $this->info('No URL-based availability sources to sync.');

            return self::SUCCESS;
        }

        foreach ($sources as $source) {
            $run = AvailabilityImportRun::create([
                'tenant_id' => $source->tenant_id,
                'source_id' => $source->id,
                'user_id' => null,
                'filename' => $source->url,
                'format' => 'url',
                'status' => 'processing',
            ]);

            try {
                $table = $ingest->fetchUrl($source->url, $source->api_token);

                if (empty($table['rows'])) {
                    $run->update(['status' => 'failed', 'error_message' => 'The availability feed lists no units.']);
                    $this->warn("[#{$source->tenant_id}] {$source->name}: no units in feed");

                    continue;
                }

                // URL feeds are authoritative: reconcile dropped units fully
                // (guardReconciliation = false) — same rule as the manual sync.
                $result = $ingest->ingest(
                    $source,
                    $table['rows'],
                    $source->tenant_id,
                    null,
                    $run->id,
                    false,
                    $table['context_url'] ?? null
                );

                $run->update([
                    'status' => 'completed',
                    'total_rows' => $result['total'],
                    'created_rows' => $result['created'],
                    'updated_rows' => $result['updated'],
                    'missing_rows' => $result['missing'],
                    'conflict_rows' => $result['conflicts'],
                    'skipped_rows' => $result['skipped'],
                    'notes' => implode("\n", array_slice($result['skipped_examples'], 0, 5)),
                ]);

                $source->forceFill(['last_imported_at' => now()])->save();

                $this->info(sprintf(
                    '[#%d] %s: %d created, %d updated, %d unlisted, %d conflicts',
                    $source->tenant_id,
                    $source->name,
                    $result['created'],
                    $result['updated'],
                    $result['missing'],
                    $result['conflicts']
                ));
            } catch (\Throwable $e) {
                $run->update([
                    'status' => 'failed',
                    'error_message' => Str::limit($e->getMessage(), 500),
                ]);
                $source->forceFill(['last_imported_at' => null])->save();

                Log::error('Availability URL sync failed', ['source_id' => $source->id, 'error' => $e->getMessage()]);
                $this->error("[#{$source->tenant_id}] {$source->name}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
