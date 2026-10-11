<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationManager;
use App\Models\Integration;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    /**
     * Update security settings (require 2FA, SSO default).
     */
    public function updateSecurity(Request $request)
    {
        $request->validate([
            'require_2fa' => 'present',
        ]);

        $tenant = auth()->user()->tenant;
        $tenant->update([
            'require_2fa' => $request->boolean('require_2fa'),
        ]);

        return back()->with('success', __('Security settings updated.'));
    }

    /**
     * Store or update an integration.
     */
    public function store(Request $request, IntegrationManager $manager)
    {
        $request->validate([
            'category' => 'required|string|in:2fa,sso,whatsapp',
            'driver' => 'required|string|max:100',
            'config' => 'nullable|array',
        ]);

        $tenant = auth()->user()->tenant;

        // Verify the driver is registered
        if (! $manager->hasDriver($request->category, $request->driver)) {
            return back()->with('error', __('Unknown integration driver.'));
        }

        $driverInstance = $manager->resolveDriver($request->category, $request->driver);

        $config = $request->config ?? [];

        // A blank password field means "keep the stored secret". The form
        // never re-renders secrets, so re-saving a config would otherwise wipe
        // the token.
        $existing = Integration::where('tenant_id', $tenant->id)
            ->where('category', $request->category)
            ->where('driver', $request->driver)
            ->first();

        if ($existing) {
            $previous = is_array($existing->config) ? $existing->config : [];

            $passwordFields = collect($driverInstance->configFields())
                ->where('type', 'password')
                ->pluck('name');

            foreach ($passwordFields as $field) {
                if (blank($config[$field] ?? null) && ! blank($previous[$field] ?? null)) {
                    $config[$field] = $previous[$field];
                }
            }
        }

        Integration::updateOrCreate(
            [
                'tenant_id' => $tenant->id,
                'category' => $request->category,
                'driver' => $request->driver,
            ],
            [
                'name' => $driverInstance->name(),
                'config' => $config,
                'is_active' => true,
                'is_default' => true,
            ]
        );

        return back()->with('success', __('Integration configured successfully.'));
    }

    /**
     * Toggle an integration on/off.
     */
    public function toggle(Integration $integration)
    {
        abort_unless($integration->tenant_id === auth()->user()->tenant_id, 403);

        $integration->update(['is_active' => ! $integration->is_active]);

        return back()->with('success', __('Integration :status.', [
            'status' => $integration->is_active ? __('enabled') : __('disabled'),
        ]));
    }

    /**
     * Remove an integration.
     */
    public function destroy(Integration $integration)
    {
        abort_unless($integration->tenant_id === auth()->user()->tenant_id, 403);

        $integration->delete();

        return back()->with('success', __('Integration removed.'));
    }
}
