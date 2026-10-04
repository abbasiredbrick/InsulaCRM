<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessRecycledPortalLeadImport;
use App\Models\AuditLog;
use App\Models\PortalIntegration;
use App\Models\RecycledPortalImportRun;
use App\Services\BusinessModeService;
use App\Services\Portals\BayutRecycledLeadSource;
use App\Services\Portals\PropertyFinderPortalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecycledPortalImportController extends Controller
{
    public function create()
    {
        return view('recycled.portal-import.create', [
            'agents' => $this->assignableAgents(),
            'portals' => $this->availablePortals(),
            'types' => BayutRecycledLeadSource::TYPES,
            'targets' => BayutRecycledLeadSource::TARGETS,
            'propertyFinderEarliest' => $this->propertyFinderEarliest()->toDateString(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'portal' => ['required', Rule::in(array_keys(RecycledPortalImportRun::PORTALS))],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'types' => ['nullable', 'array'],
            'types.*' => ['string', Rule::in(array_keys(BayutRecycledLeadSource::TYPES))],
            'targets' => ['nullable', 'array'],
            'targets.*' => ['string', Rule::in(array_keys(BayutRecycledLeadSource::TARGETS))],
            'agent_id' => ['nullable', 'integer'],
        ]);

        $criteria = $this->criteria($request, $data);
        $integration = $this->integrationFor($criteria['portal']);

        if (! $integration) {
            throw ValidationException::withMessages([
                'portal' => __('Configure and activate this portal API before starting a pull.'),
            ]);
        }

        $run = RecycledPortalImportRun::create([
            'tenant_id' => auth()->user()->tenant_id,
            'portal_integration_id' => $integration->id,
            'user_id' => auth()->id(),
            'portal' => $criteria['portal'],
            'mode' => 'preview',
            'status' => 'queued',
            'criteria' => $criteria,
            'cursor' => [],
        ]);

        AuditLog::log('recycled.portal_import.queued', $run, null, [
            'portal' => $run->portal,
            'mode' => 'preview',
            'criteria' => $criteria,
        ]);

        ProcessRecycledPortalLeadImport::dispatch($run->id);

        return redirect()
            ->route('recycled.portal-import.show', $run)
            ->with('success', __('The portal preview has started. No pool records will be written until you confirm it.'));
    }

    public function show(RecycledPortalImportRun $run)
    {
        return view('recycled.portal-import.show', [
            'run' => $run,
            'portals' => RecycledPortalImportRun::PORTALS,
            'statuses' => RecycledPortalImportRun::STATUSES,
        ]);
    }

    public function confirm(RecycledPortalImportRun $run)
    {
        $confirmed = DB::transaction(function () use ($run) {
            $locked = RecycledPortalImportRun::query()->lockForUpdate()->find($run->id);

            if (! $locked || $locked->mode !== 'preview' || ! in_array($locked->status, ['ready', 'ready_with_errors'], true)) {
                return null;
            }

            if (! $this->integrationFor($locked->portal)) {
                return false;
            }

            $previewCounts = [
                'observed' => $locked->observed_count,
                'contactable' => $locked->contactable_count,
                'available' => $locked->imported_count,
                'already_active' => $locked->already_active_count,
                'duplicates' => $locked->duplicate_count,
                'out_of_range' => $locked->out_of_range_count,
                'invalid' => $locked->skipped_invalid_count,
                'no_contact' => $locked->skipped_no_contact_count,
                'warnings' => $locked->error_count,
            ];

            $locked->update([
                'mode' => 'import',
                'status' => 'queued',
                'cursor' => [],
                'preview_counts' => $previewCounts,
                'observed_count' => 0,
                'out_of_range_count' => 0,
                'skipped_invalid_count' => 0,
                'skipped_no_contact_count' => 0,
                'contactable_count' => 0,
                'imported_count' => 0,
                'already_active_count' => 0,
                'duplicate_count' => 0,
                'enriched_count' => 0,
                'error_count' => 0,
                'errors' => [],
                'started_at' => null,
                'completed_at' => null,
            ]);

            AuditLog::log('recycled.portal_import.confirmed', $locked, null, ['preview' => $previewCounts]);

            return $locked;
        });

        if ($confirmed === false) {
            return back()->with('error', __('Configure and activate this portal API before importing.'));
        }

        if (! $confirmed) {
            return back()->with('error', __('This preview is no longer available to import.'));
        }

        ProcessRecycledPortalLeadImport::dispatch($confirmed->id);

        return redirect()
            ->route('recycled.portal-import.show', $confirmed)
            ->with('success', __('The confirmed import has started.'));
    }

    public function cancel(RecycledPortalImportRun $run)
    {
        $result = DB::transaction(function () use ($run) {
            $locked = RecycledPortalImportRun::query()->lockForUpdate()->find($run->id);

            if (! $locked) {
                return null;
            }

            if ($locked->status === 'cancelled') {
                return 'already_cancelled';
            }

            if (! in_array($locked->status, ['queued', 'running'], true)) {
                return 'finished';
            }

            $locked->update([
                'status' => 'cancelled',
                'completed_at' => now(),
            ]);

            AuditLog::log('recycled.portal_import.cancelled', $locked);

            return 'cancelled';
        });

        if ($result === 'already_cancelled') {
            return back()->with('success', __('This portal import is already cancelled.'));
        }

        if ($result !== 'cancelled') {
            return back()->with('error', __('This portal import has already finished.'));
        }

        return back()->with('success', __('The portal import was cancelled.'));
    }

    protected function criteria(Request $request, array $data): array
    {
        $portal = $data['portal'];
        $timezone = auth()->user()->tenant->timezone ?: config('app.timezone');
        $today = now($timezone)->startOfDay();
        $dateFrom = CarbonImmutable::parse($data['date_from'], $timezone)->startOfDay();
        $dateTo = CarbonImmutable::parse($data['date_to'], $timezone)->endOfDay();

        if ($dateTo->gt(now($timezone)->endOfDay())) {
            throw ValidationException::withMessages(['date_to' => __('The end date cannot be in the future.')]);
        }

        $types = $portal === 'property_finder'
            ? []
            : array_values((array) ($request->input('types') ?? array_keys(BayutRecycledLeadSource::TYPES)));
        $targets = $portal === 'property_finder'
            ? []
            : array_values((array) ($request->input('targets') ?? ['listing', 'agent', 'agency']));

        if ($portal !== 'property_finder' && ($types === [] || $targets === [])) {
            throw ValidationException::withMessages([
                $types === [] ? 'types' : 'targets' => __('Select at least one option.'),
            ]);
        }

        if ($portal === 'property_finder' && $dateFrom->lt($this->propertyFinderEarliest())) {
            throw ValidationException::withMessages([
                'date_from' => __('Property Finder’s API only returns the last 89 days of leads. Choose a later start date, or export the older range as CSV from PF Expert (up to 180 days per file, 3 years of history) and use the CSV importer.'),
            ]);
        }

        $agentId = $data['agent_id'] ?? null;

        if ($agentId !== null && ! $this->assignableAgents()->has((int) $agentId)) {
            throw ValidationException::withMessages(['agent_id' => __('Select a valid agent.')]);
        }

        return [
            'portal' => $portal,
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'types' => array_fill_keys($types, true),
            'targets' => array_fill_keys($targets, true),
            'trulead' => 1,
            'agent_id' => $agentId,
            'country' => auth()->user()->tenant->country,
            'timezone' => $timezone,
        ];
    }

    protected function availablePortals(): array
    {
        $available = [];

        if ($this->integrationFor('bayut')) {
            $available['bayut'] = 'Bayut';
            $available['dubizzle'] = 'Dubizzle';
        }

        if ($this->integrationFor('property_finder')) {
            $available['property_finder'] = 'Property Finder';
        }

        return $available;
    }

    protected function integrationFor(string $portal): ?PortalIntegration
    {
        $integrationPortal = $portal === 'property_finder' ? 'propertyfinder' : 'bayut';
        $integration = PortalIntegration::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where('portal', $integrationPortal)
            ->where('is_active', true)
            ->first();

        if (! $integration) {
            return null;
        }

        $configured = $portal === 'property_finder'
            ? filled($integration->api_token) && filled($integration->api_secret)
            : filled($integration->leads_api_token);

        return $configured ? $integration : null;
    }

    protected function propertyFinderEarliest(): CarbonImmutable
    {
        return CarbonImmutable::now(auth()->user()->tenant->timezone ?: config('app.timezone'))
            ->subDays(PropertyFinderPortalService::LEADS_MAX_LOOKBACK_DAYS)
            ->startOfDay();
    }

    protected function assignableAgents()
    {
        return \App\Models\User::query()
            ->where('tenant_id', auth()->user()->tenant_id)
            ->where(function ($query) {
                $query->whereHas('role', fn ($q) => $q->whereIn('name', BusinessModeService::getAssignableRoleNames()))
                    ->orWhereHas('secondaryRoles', fn ($q) => $q->whereIn('name', BusinessModeService::getAssignableRoleNames()));
            })
            ->orderBy('name')
            ->get(['id', 'name'])
            ->pluck('name', 'id');
    }
}
