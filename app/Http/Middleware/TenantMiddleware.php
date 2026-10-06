<?php

namespace App\Http\Middleware;

use App\Services\TenantMailConfigurator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class TenantMiddleware
{
    public function __construct(private TenantMailConfigurator $mail) {}

    /**
     * Ensure the authenticated user belongs to an active tenant.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $user = auth()->user();

            // Check tenant is active
            if (! $user->tenant || $user->tenant->status !== 'active') {
                auth()->logout();

                return redirect()->route('login')->withErrors([
                    'email' => 'Your account has been suspended. Please contact support.',
                ]);
            }

            // Set application locale from tenant preference
            $locale = $user->tenant->locale ?? 'en';
            if (file_exists(lang_path("{$locale}.json")) || $locale === 'en') {
                App::setLocale($locale);
            }

            // Share business mode with all Blade views
            $businessMode = $user->tenant->business_mode ?? 'wholesale';
            view()->share('businessMode', $businessMode);
            view()->share('modeTerms', \App\Services\BusinessModeService::getTerminology($user->tenant));

            // Apply tenant mail settings if configured — overrides .env defaults.
            // Public routes with no session must do this themselves; see
            // TenantMailConfigurator.
            $this->mail->apply($user->tenant);
        }

        return $next($request);
    }
}
