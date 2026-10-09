<?php

namespace App\Http\Middleware;

use App\Jobs\SyncOverduePortalLeads;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps portal leads arriving even when the scheduler is not running.
 *
 * The scheduled pull in bootstrap/app.php is the real trigger, but a shared
 * host with no cron entry never runs it, and the only visible symptom is that
 * new Property Finder leads turn up after somebody clicks "Sync leads" by hand.
 * This middleware is the safety net for that case.
 *
 * Cost per request is a single cache read: a throttle marker is set when a
 * check runs and is honoured until it expires, so at most one check per
 * interval reaches the database. The job it dispatches is itself a no-op
 * unless a pull is genuinely overdue, and the sync service takes a lock, so
 * two triggers landing on the same minute are harmless.
 */
class CatchUpPortalLeads
{
    /**
     * How often, at most, a request is allowed to consider a catch-up. Three
     * minutes - tight enough that a page view tops up a fresh integration, and
     * aligned with PortalLeadSyncService::OVERDUE_AFTER_MINUTES so a due pull
     * is not throttled away behind its own window.
     */
    protected const THROTTLE_SECONDS = 180;

    /**
     * The marker is set before the job is dispatched, not after, so a burst of
     * concurrent requests cannot all decide they are the first.
     */
    protected const THROTTLE_KEY = 'portal-leads-catch-up:due';

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->shouldCheck($request)) {
            // afterResponse() runs the pull once the browser already has its
            // page, so nobody waits on a multi-second walk of the portal API.
            SyncOverduePortalLeads::dispatch()->afterResponse();
        }

        return $next($request);
    }

    /**
     * True for the first signed-in request of each window. The marker doubles as
     * the in-flight flag: Cache::add() only stores the key when it is absent,
     * so exactly one request per window proceeds.
     */
    protected function shouldCheck(Request $request): bool
    {
        // Behind the auth middleware? This runs on the whole web group, and
        // unauthenticated traffic - crawlers, the login page - would only burn
        // the throttle window on a trigger nobody is waiting for.
        if (! $request->user()) {
            return false;
        }

        // Laravel's test harness fires terminating callbacks, so a dispatch
        // here would attempt real portal HTTP from every feature test. The
        // behaviour worth testing lives in PortalLeadSyncService and
        // SyncOverduePortalLeads, which are exercised directly instead.
        if (app()->runningUnitTests()) {
            return false;
        }

        try {
            return Cache::add(self::THROTTLE_KEY, now()->timestamp, self::THROTTLE_SECONDS);
        } catch (\Throwable) {
            // A broken cache must never take the app down with it - the
            // scheduled command is still the primary path.
            return false;
        }
    }
}
