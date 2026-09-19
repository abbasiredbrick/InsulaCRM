<?php

namespace App\Services;

use App\Models\AgentCompensation;
use App\Models\Lead;
use App\Models\LeadAgent;
use App\Models\LeadCommission;
use Illuminate\Support\Collection;

/**
 * Splits a lead's gross commission between the company, the owning agent and
 * every co-agent / external collaborator on the lead, then snapshots the result.
 *
 * Rules implemented:
 *  - Agreed company/agent split (e.g. 40/60) defined in the tenant formula or
 *    per-agent plan; tiered schedules keyed on the total commission amount.
 *  - Salaried listing/cold-call agents are paid a fixed amount per close,
 *    and their co-agents are funded from that fixed amount (and the company).
 *  - Support agents get their own share of the gross (commission_pct), funded
 *    either fully by the main agent, fully by the company, or half/half.
 *  - The snapshot freezes the split at close time; paid rows are never touched.
 */
class CommissionCalculationService
{
    public function calculate(Lead $lead, ?float $gross = null, bool $persist = true): array
    {
        $tenant = $lead->tenant;

        if (! $tenant) {
            throw new \InvalidArgumentException('Lead has no tenant.');
        }

        $settings = $tenant->commissionCalculationSettings();
        $gross = round((float) ($gross ?? $lead->commissionBasis()), 2);

        if ($gross <= 0) {
            throw new \RuntimeException(__('The lead has no gross commission to split.'));
        }

        [$companyShare, $mainShare, $mainPct, $mainBasis] = $this->resolveMainAgent($lead, $settings, $gross);

        $rows = [];
        $warnings = [];

        // Co-agents and external collaborators. Their share is always a % of
        // the gross; the funding source decides who pays for it.
        foreach ($lead->activeLeadAgents as $la) {
            $spct = (float) ($la->commission_pct ?? 0);

            if ($spct <= 0) {
                $warnings[] = __('Co-agent :name has no commission % set — skipped.', ['name' => $la->display_name]);
                continue;
            }

            $share = round($gross * ($spct / 100), 2);
            $funding = $la->share_funding ?: $settings['default_support_funding'];

            [$companyShare, $mainShare] = $this->fundShare($funding, $share, $companyShare, $mainShare);

            $rows[] = $this->row(
                type: $la->isExternal() ? LeadCommission::TYPE_EXTERNAL : LeadCommission::TYPE_INTERNAL,
                agentId: $la->agent_id,
                name: $la->isExternal() ? $la->display_name : null,
                email: $la->isExternal() ? $la->external_email : null,
                gross: $gross,
                pct: $spct,
                amount: $share,
                funding: $funding,
                basis: 'support',
                lead: $lead,
            );
        }

        // The owning agent's share, post funding deductions.
        $rows[] = $this->row(
            type: LeadCommission::TYPE_INTERNAL,
            agentId: $lead->agent_id,
            name: null,
            email: null,
            gross: $gross,
            pct: $gross > 0 ? round((float) $mainShare / $gross * 100, 2) : 0,
            amount: round($mainShare, 2),
            funding: null,
            basis: $mainBasis,
            lead: $lead,
        );

        // The company keeps whatever is left after all agent shares.
        $rows[] = $this->row(
            type: LeadCommission::TYPE_COMPANY,
            agentId: null,
            name: $tenant->name,
            email: null,
            gross: $gross,
            pct: 100 - (float) (count($rows) ? array_sum(array_column($rows, 'share_pct')) : 0),
            amount: round($companyShare, 2),
            funding: null,
            basis: $mainBasis,
            lead: $lead,
        );

        if ($companyShare < 0 || $mainShare < 0) {
            $warnings[] = __('Co-agent shares exceed the available commission — the company/main agent share was clamped to zero.');
        }

        $result = compact('gross', 'rows', 'warnings');

        if ($persist) {
            $this->snapshot($lead, $rows);
            $result['snapshot_id'] = $lead->commissions()->where('status', LeadCommission::STATUS_EARNED)->first()?->commissioned_at;
        }

        return $result;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: string} [company, main, mainPct, basis]
     */
    protected function resolveMainAgent(Lead $lead, array $settings, float $gross): array
    {
        $plan = $lead->agent?->commissionPlan;

        if ($plan && $plan->isFixedAmount()) {
            $fixed = (float) $plan->fixed_amount_per_close;
            $main = min($fixed, $gross);
            $company = max(0.0, $gross - $main);

            return [$company, $main, $gross > 0 ? round($main / $gross * 100, 2) : 0, 'fixed_amount'];
        }

        if ($plan && $plan->isTiered()) {
            $agentPct = $this->tieredAgentPct($settings, $gross);

            return [round($gross * ((100 - $agentPct) / 100), 2), round($gross * ($agentPct / 100), 2), $agentPct, 'tiered'];
        }

        if ($plan) {
            $agentPct = (float) ($plan->agent_pct ?? 50);

            return [round($gross * ((100 - $agentPct) / 100), 2), round($gross * ($agentPct / 100), 2), $agentPct, 'fixed'];
        }

        // No per-agent plan → fall back to the tenant formula.
        if (($settings['default_split_type'] ?? 'fixed') === 'tiered') {
            $agentPct = $this->tieredAgentPct($settings, $gross);

            return [round($gross * ((100 - $agentPct) / 100), 2), round($gross * ($agentPct / 100), 2), $agentPct, 'tiered'];
        }

        $agentPct = (float) ($settings['default_agent_pct'] ?? 50);

        return [round($gross * ((100 - $agentPct) / 100), 2), round($gross * ($agentPct / 100), 2), $agentPct, 'fixed'];
    }

    protected function fundShare(string $funding, float $share, float $company, float $main): array
    {
        return match ($funding) {
            LeadAgent::FUNDING_FROM_COMPANY => [max(0.0, $company - $share), $main],
            LeadAgent::FUNDING_FROM_BOTH => [
                max(0.0, $company - round($share / 2, 2)),
                max(0.0, $main - round($share / 2, 2)),
            ],
            default => [$company, max(0.0, $main - $share)], // from_agent
        };
    }

    protected function row(string $type, ?int $agentId, ?string $name, ?string $email, float $gross, float $pct, float $amount, ?string $funding, string $basis, Lead $lead): array
    {
        return [
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $agentId,
            'participant_type' => $type,
            'participant_name' => $name,
            'participant_email' => $email,
            'gross_commission' => $gross,
            'share_pct' => $pct,
            'amount' => round(max(0.0, $amount), 2),
            'funding_source' => $funding,
            'basis' => $basis,
        ];
    }

    protected function tieredAgentPct(array $settings, float $gross): float
    {
        $tiers = $settings['tiers'] ?? [];

        foreach ($tiers as $tier) {
            $from = isset($tier['from']) && $tier['from'] !== null ? (float) $tier['from'] : null;
            $max = isset($tier['max']) && $tier['max'] !== null ? (float) $tier['max'] : null;

            $inRange = ($from === null || $gross >= $from) && ($max === null || $gross <= $max);

            if ($inRange) {
                return (float) $tier['agent_pct'];
            }
        }

        $last = end($tiers);

        return (float) ($last['agent_pct'] ?? 50);
    }

    /**
     * Replace the current earned snapshot with freshly computed rows. Paid rows
     * are preserved (their share is no longer editable).
     */
    protected function snapshot(Lead $lead, array $rows): void
    {
        $lead->commissions()->where('status', LeadCommission::STATUS_EARNED)->delete();

        foreach ($rows as $row) {
            $lead->commissions()->create(array_merge($row, [
                'status' => LeadCommission::STATUS_EARNED,
                'commissioned_at' => now(),
            ]));
        }
    }

    /**
     * Human-friendly split label for display, e.g. "50 / 50" or "Tiered" or
     * "Fixed amount".
     */
    public function splitLabel(?Lead $lead): string
    {
        if (! $lead) {
            return __('—');
        }

        $plan = $lead->agent?->commissionPlan;

        if ($plan && $plan->isFixedAmount()) {
            return __('Fixed :amount', ['amount' => \App\Helpers\TenantFormatHelper::currency((float) $plan->fixed_amount_per_close)]);
        }

        if ($plan && $plan->isTiered()) {
            return __('Tiered');
        }

        if ($plan) {
            return rtrim(rtrim((string) $plan->company_pct, '0'), '.').' / '.rtrim(rtrim((string) $plan->agent_pct, '0'), '.');
        }

        $settings = $lead->tenant?->commissionCalculationSettings() ?? [];

        if (($settings['default_split_type'] ?? 'fixed') === 'tiered') {
            return __('Tiered');
        }

        return rtrim(rtrim((string) ($settings['default_company_pct'] ?? 50), '0'), '.').' / '.rtrim(rtrim((string) ($settings['default_agent_pct'] ?? 50), '0'), '.');
    }
}