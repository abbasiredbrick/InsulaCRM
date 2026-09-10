<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PortalIntegration;
use App\Services\Portals\BayutPortalService;
use App\Services\Portals\PortalLeadService;
use App\Services\Portals\PortalPayloadNormalizer;
use App\Services\Portals\PropertyFinderPortalService;
use Illuminate\Http\Request;

class PortalIntegrationController extends Controller
{
    protected const PORTALS = ['bayut', 'propertyfinder'];

    public function index()
    {
        $integrations = [];
        foreach (self::PORTALS as $portal) {
            $integrations[$portal] = PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
                ->where('portal', $portal)
                ->first();
        }

        return view('settings.portal-integrations', [
            'integrations' => $integrations,
        ]);
    }

    public function update(Request $request, string $portal)
    {
        abort_unless(in_array($portal, self::PORTALS, true), 404);

        $data = $request->validate([
            'api_token'   => 'nullable|string',
            'api_secret'  => 'nullable|string',
            'base_url'    => 'nullable|url|max:255',
            'agent_reference' => 'nullable|string|max:100',
            'public_profile_id' => 'nullable|string|max:100',
            'default_location_id' => 'nullable|string|max:100',
            'webhook_secret' => 'nullable|string',
        ]);

        $integration = PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', $portal)
            ->first();

        if ($integration === null) {
            $integration = PortalIntegration::make([
                'tenant_id' => auth()->user()->tenant_id,
                'portal'    => $portal,
            ]);
        }

        if (blank($data['api_token'] ?? null)) {
            unset($data['api_token']);
        }
        if (blank($data['api_secret'] ?? null)) {
            unset($data['api_secret']);
        }
        if (blank($data['webhook_secret'] ?? null)) {
            unset($data['webhook_secret']);
        }

        $data['is_active'] = true;
        $data['last_error'] = null;

        $integration->fill($data)->save();

        AuditLog::log('settings.portal_integration_' . $portal . '_updated', $integration);

        return back()->with('success', __(':portal integration saved.', ['portal' => $integration->portal_label]));
    }

    public function toggle(string $portal)
    {
        abort_unless(in_array($portal, self::PORTALS, true), 404);

        $integration = PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', $portal)
            ->firstOrFail();

        $integration->update(['is_active' => ! $integration->is_active]);

        AuditLog::log('settings.portal_integration_' . $portal . ($integration->is_active ? '_enabled' : '_disabled'), $integration);

        return back()->with('success', __(':portal integration :status.', [
            'portal' => $integration->portal_label,
            'status' => $integration->is_active ? __('enabled') : __('disabled'),
        ]));
    }

    public function test(Request $request, string $portal)
    {
        abort_unless(in_array($portal, self::PORTALS, true), 404);

        $integration = PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', $portal)
            ->where('is_active', true)
            ->first();

        if ($integration === null) {
            return back()->with('error', __('Save the integration first.'));
        }

        $service = $portal === 'bayut'
            ? new BayutPortalService($integration)
            : new PropertyFinderPortalService($integration);

        $result = $service->test();

        $integration->update([
            'last_error' => $result['ok'] ? null : ($result['message'] ?? null),
        ]);

        AuditLog::log('settings.portal_integration_' . $portal . '_tested', $integration, ['ok' => $result['ok']]);

        if ($result['ok']) {
            return back()->with('success', $result['message']);
        }

        return back()->with('error', $result['message'] ?? __('Connection failed.'));
    }

    public function syncLeads(Request $request, string $portal)
    {
        abort_unless($portal === 'propertyfinder', 404);

        $integration = PortalIntegration::where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', 'propertyfinder')
            ->where('is_active', true)
            ->first();

        if ($integration === null) {
            return back()->with('error', __('No active Property Finder integration.'));
        }

        $service = new PropertyFinderPortalService($integration);
        $leads = $service->fetchLeads($integration->last_synced_at?->toIso8601String());

        $created = 0;
        $ignored = 0;
        foreach ($leads as $raw) {
            if (! is_array($raw)) {
                continue;
            }

            $normalized = (new PortalPayloadNormalizer)->normalize('propertyfinder', $raw);
            $lead = (new PortalLeadService)->createFromPayload($integration, 'property_finder', $normalized);

            $lead === null ? $ignored++ : $created++;
        }

        $integration->update([
            'last_synced_at' => now(),
            'last_error'     => null,
        ]);

        AuditLog::log('settings.portal_integration_propertyfinder_synced', $integration, compact('created', 'ignored'));

        return back()->with('success', __('Synced :created new leads (:ignored existing).', compact('created', 'ignored')));
    }
}