<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\RecycledLead;
use App\Models\Tenant;

/**
 * The auto-recycle rule: realestate leads that have been sitting in a terminal
 * / passive status (nurture, closed_lost, dead) for 60+ days are moved out of
 * the live pipeline into the Recycled Leads pool so agents can re-engage them.
 */
class LeadRecycleService
{
    /**
     * Statuses a lead may be recycled from.
     *
     * @var list<string>
     */
    public const RECYCLE_STATUSES = ['nurture', 'closed_lost', 'dead'];

    /**
     * Days a lead must have sat undisturbed in a recyclable status.
     */
    public const RECYCLE_AFTER_DAYS = 60;

    /**
     * Run the recycle scan for one or all tenant(s).
     *
     * @return array{recycled: int, per_tenant: array<int, int>}
     */
    public function recycle(?Tenant $tenant = null): array
    {
        $tenants = $tenant
            ? collect([$tenant])
            : Tenant::query()->where('business_mode', 'realestate')->get();

        $perTenant = [];

        foreach ($tenants as $t) {
            $perTenant[$t->id] = $this->recycleTenant($t);
        }

        return [
            'recycled' => array_sum($perTenant),
            'per_tenant' => $perTenant,
        ];
    }

    /**
     * Recycle the overdue leads of a single tenancy.
     */
    public function recycleTenant(Tenant $tenant): int
    {
        $cutoff = now()->subDays(self::RECYCLE_AFTER_DAYS);

        $candidates = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', self::RECYCLE_STATUSES)
            ->whereNull('recycled_at')
            ->where(function ($q) use ($cutoff) {
                $q->where(function ($q2) use ($cutoff) {
                    $q2->whereNotNull('status_changed_at')->where('status_changed_at', '<=', $cutoff);
                })->orWhere(function ($q2) use ($cutoff) {
                    // Legacy rows predate status_changed_at; use last update as
                    // the beacon so fresh leads are never recycled at rollout.
                    $q2->whereNull('status_changed_at')->where('updated_at', '<=', $cutoff);
                });
            })
            ->get();

        $count = 0;

        foreach ($candidates as $lead) {
            if ($lead->do_not_contact || $lead->isOnDncList()) {
                continue;
            }

            $alreadyPooled = RecycledLead::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('original_lead_id', $lead->id)
                ->exists();

            if ($alreadyPooled) {
                continue;
            }

            $stage = $lead->stageLabel() ?: '—';

            RecycledLead::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'source' => 'auto_recycle',
                'portal' => in_array($lead->lead_source, array_keys(RecycledLead::PORTALS), true)
                    ? $lead->lead_source
                    : null,
                'reference' => $lead->reference,
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'phone' => $lead->phone,
                'email' => $lead->email,
                'original_deal_type' => $lead->deal_type,
                'status' => 'pending',
                'original_lead_id' => $lead->id,
                'notes' => "Auto-recycled: lead was {$stage} in status '{$lead->status}' for "
                    .self::RECYCLE_AFTER_DAYS.'+ days without movement.',
                'recycled_at' => now(),
            ]);

            $lead->update(['recycled_at' => now()]);

            $count++;
        }

        return $count;
    }
}
