<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\RecycledPortalImportRun;
use App\Services\RecycledPortalLeadImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Throwable;

class ProcessRecycledPortalLeadImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $runId) {}

    public function handle(RecycledPortalLeadImportService $service): void
    {
        $run = RecycledPortalImportRun::withoutGlobalScopes()->find($this->runId);

        if (! $run) {
            return;
        }

        $result = $service->processNext($run);

        if ($result['dispatch_next']) {
            static::dispatch($run->id);
        }
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("recycled-portal-import:{$this->runId}", 5, 65))->shared(),
        ];
    }

    public function backoff(): array
    {
        return [5, 15];
    }

    public function failed(?Throwable $exception): void
    {
        $run = RecycledPortalImportRun::withoutGlobalScopes()->find($this->runId);

        if (! $run || in_array($run->status, ['completed', 'completed_with_errors', 'cancelled'], true)) {
            return;
        }

        $message = $exception?->getMessage() ?? 'The portal import stopped unexpectedly.';
        $errors = array_values(array_filter((array) $run->errors));
        $errors[] = Str::limit($message, 500);

        $run->update([
            'status' => 'failed',
            'errors' => array_slice($errors, 0, 50),
            'error_count' => $run->error_count + 1,
            'completed_at' => now(),
        ]);

        AuditLog::create([
            'tenant_id' => $run->tenant_id,
            'user_id' => $run->user_id,
            'action' => 'recycled.portal_import.failed',
            'model_type' => $run::class,
            'model_id' => $run->id,
            'new_values' => ['status' => 'failed', 'error' => Str::limit($message, 300)],
        ]);
    }
}
