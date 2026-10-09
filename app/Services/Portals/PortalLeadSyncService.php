<?php

namespace App\Services\Portals;

use App\Models\AuditLog;
use App\Models\PortalIntegration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * One place that pulls new portal leads into the CRM, whichever trigger asks
 * for it: the manual "Sync leads" button, the scheduled command, or the
 * in-app catch-up that keeps leads flowing when no cron is running.
 *
 * Every entry point used to re-implement the same three things - honour
 * is_active, take the leads_last_synced_at cursor, and only advance it on a
 * clean run - and they drifted apart (the scheduled pull ignored is_active
 * entirely). Keeping it here means a trigger cannot reintroduce that drift.
 */
class PortalLeadSyncService
{
    /**
     * How far each pull reaches back beyond the cursor. Portal APIs do not
     * guarantee that every lead is listed the moment its createdAt passes the
     * cursor — Property Finder's messaging/replied WhatsApp leads in
     * particular can surface well after the fact. A window that only ever
     * moves forward skips those forever. Each pull re-requests this overlap;
     * createFromPayload() de-duplicates by portal reference, so re-reading the
     * tail is cheap and idempotent.
     */
    public const PULL_OVERLAP_MINUTES = 180;

    /**
     * The cursor only moves on a clean run, so even WITHOUT the overlap a hard
     * failure re-reads the same window next time; the overlap additionally
     * re-covers leads the portal itself was late to serve.
     */
    public function __construct(
        protected int $overlapMinutes = self::PULL_OVERLAP_MINUTES,
    ) {}

    /**
     * A pull is overdue once the cursor is older than this. Matches the
     * catch-up middleware's three-minute throttle so a page view can top up a
     * fresh integration, while the scheduler keeps forcing a pull on its own
     * cadence regardless.
     */
    public const OVERDUE_AFTER_MINUTES = 3;

    /**
     * A portal that has never synced is always overdue - a fresh integration
     * must not wait for its first schedule tick.
     */
    public const NEVER = 'never';

    /**
     * When the settings screen calls the pull "stale". Deliberately far looser
     * than OVERDUE_AFTER_MINUTES so a healthy integration is not shown as
     * broken minutes after a clean sync just because the tight pull window
     * elapsed - the two concerns are separate, and each has its own constant.
     */
    public const STALE_AFTER_MINUTES = 60;

    /**
     * Whether this integration has the credentials its portal needs to pull
     * leads at all.
     *
     * The two portals disagree on the column: Property Finder pulls with the
     * main api_token/api_secret pair, Bayut with the separate leads_api_token.
     * Filtering on the wrong one silently skips a whole portal - which is how
     * an integration quietly stops syncing without ever reporting an error.
     */
    public function canPull(PortalIntegration $integration): bool
    {
        return $integration->portal === 'bayut'
            ? filled($integration->leads_api_token)
            : filled($integration->api_token) && filled($integration->api_secret);
    }

    /**
     * Every active integration that is able to pull leads, for the scheduled
     * command and the catch-up to walk.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PortalIntegration>
     */
    public function pullable(?int $tenantId = null)
    {
        return PortalIntegration::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get()
            ->filter(fn (PortalIntegration $i) => $this->canPull($i))
            ->values();
    }

    /**
     * Pull leads for one integration and advance its cursor.
     *
     * Returns ['created' => int, 'ignored' => int, 'error' => ?string,
     * 'skipped' => bool]. `skipped` means another pull held the lock, so no
     * request was made at all.
     */
    public function pull(PortalIntegration $integration, bool $force = false): array
    {
        if (! $integration->is_active) {
            return $this->skipped(__('The :portal integration is switched off.', ['portal' => $integration->portal]));
        }

        if (! $force && ! $this->isDue($integration)) {
            return $this->skipped();
        }

        // One pull per integration at a time. The scheduled command and the
        // catch-up can both be looking at the same row on a busy minute, and
        // Property Finder only serves 89 days of lead history - overlapping
        // pulls would spend that budget re-reading pages we already have.
        $lock = Cache::lock($this->lockKey($integration), 300);

        if (! $lock->get()) {
            return $this->skipped();
        }

        try {
            // Re-read inside the lock: the process that held it may have just
            // advanced the cursor past the point where this one read it.
            $integration->refresh();

            return $this->doPull($integration);
        } finally {
            $lock->release();
        }
    }

    /**
     * Do the actual work for a single integration, assuming the lock is held.
     */
    protected function doPull(PortalIntegration $integration): array
    {
        try {
            // Re-cover the last PULL_OVERLAP_MINUTES on every run so a lead the
            // portal was late to list is still re-requested once the cursor has
            // moved past its createdAt. A cursor older than the overlap (dead
            // sync) still pulls from the cursor itself, never pruning history.
            $since = $integration->leads_last_synced_at;
            $overlap = now()->subMinutes($this->overlapMinutes);
            if ($since !== null && $since->gt($overlap)) {
                $since = $overlap;
            }

            $result = $integration->portal === 'bayut'
                ? (new BayutLeadsPullService($integration))->pull($since)
                : (new PropertyFinderPortalService($integration))->pullLeads($since);
        } catch (\Throwable $e) {
            Log::error('Portal lead pull failed', [
                'integration_id' => $integration->id,
                'portal' => $integration->portal,
                'error' => $e->getMessage(),
            ]);

            $integration->update(['leads_last_error' => Str::limit($e->getMessage(), 500)]);

            return $this->result(error: $e->getMessage());
        }

        $error = $result['error'] ?? null;

        // The cursor only moves on a clean run. A partial failure re-reads the
        // same window next time rather than stepping over the leads it missed.
        $integration->refresh();
        $integration->update([
            'leads_last_synced_at' => $error === null ? now() : $integration->leads_last_synced_at,
            'leads_last_error' => $error,
        ]);

        AuditLog::log($this->auditAction($integration), $integration, [
            'created' => $result['created'] ?? 0,
            'ignored' => $result['ignored'] ?? 0,
        ]);

        Log::info('Portal lead pull finished', [
            'integration_id' => $integration->id,
            'portal' => $integration->portal,
            'created' => $result['created'] ?? 0,
            'ignored' => $result['ignored'] ?? 0,
            'error' => $error,
        ]);

        return [
            'created' => $result['created'] ?? 0,
            'ignored' => $result['ignored'] ?? 0,
            'error' => $error,
            'skipped' => false,
        ];
    }

    /**
     * Whether a pull is warranted right now: either the cursor is due to move,
     * or it has gone stale and we need to catch up.
     */
    public function isDue(PortalIntegration $integration): bool
    {
        $last = $integration->leads_last_synced_at;

        if ($last === null) {
            return true;
        }

        return $last->lt(now()->subMinutes(self::OVERDUE_AFTER_MINUTES));
    }

    /**
     * Per-integration pull freshness, keyed by integration id, for the settings
     * screen. Per-integration rather than one global figure: a healthy Bayut
     * pull must not make a stale Property Finder one look fine.
     *
     * @return array<int, array{state: string, at: ?\Illuminate\Support\Carbon}>
     */
    public function staleness(): array
    {
        return $this->pullable()->mapWithKeys(fn ($integration) => [
            $integration->id => [
                'state' => $integration->leads_last_synced_at === null
                    ? self::NEVER
                    : ($this->isStale($integration) ? 'stale' : 'ok'),
                'at' => $integration->leads_last_synced_at,
            ],
        ])->all();
    }

    /**
     * Whether the cursor looks abandoned to a human on the settings screen.
     * Deliberately decoupled from isDue(): the sync window is tight (3 minutes)
     * and elapses on every healthy integration between pulls, so the display
     * "stale" verdict must come from its own far looser threshold.
     */
    public function isStale(PortalIntegration $integration): bool
    {
        return $integration->leads_last_synced_at?->lt(now()->subMinutes(self::STALE_AFTER_MINUTES)) ?? true;
    }

    protected function lockKey(PortalIntegration $integration): string
    {
        return 'portal-leads-pull:'.$integration->id;
    }

    /**
     * The audit action each portal has always written under. These strings are
     * already sitting in production audit rows, so they are spelled out rather
     * than interpolated - the Property Finder one never had "_leads" in it.
     */
    protected function auditAction(PortalIntegration $integration): string
    {
        return $integration->portal === 'bayut'
            ? 'settings.portal_integration_bayut_leads_synced'
            : 'settings.portal_integration_propertyfinder_synced';
    }

    protected function result(int $created = 0, int $ignored = 0, ?string $error = null, bool $skipped = false): array
    {
        return compact('created', 'ignored', 'error', 'skipped');
    }

    protected function skipped(?string $error = null): array
    {
        return $this->result(error: $error, skipped: true);
    }
}
