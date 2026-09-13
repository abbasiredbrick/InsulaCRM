<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

class LeadReferenceService
{
    /**
     * Human-readable lead identifier: {YY}{MM}-{AGENTCODE}-{SEQ} e.g. 2609-AJ07-0001.
     */
    public const FORMULA = '{YY}{MM}-{AGENTCODE}-{SEQ}';

    public function format(string $yearMonth, string $agentCode, int $sequence): string
    {
        return $yearMonth . '-' . $agentCode . '-' . str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Generate a reference for a lead using the agent's code and a per-agent
     * per-month sequence. Existing lead references are never overwritten.
     */
    public function generate(Lead $lead): string
    {
        $tenantId = $lead->tenant_id;
        $yearMonth = $this->yearMonth($tenantId, $lead->created_at);

        $agentCode = $this->agentCodeFor($lead);
        $sequence = $this->nextSequence($tenantId, $yearMonth, $agentCode);

        return $this->format($yearMonth, $agentCode, $sequence);
    }

    /**
     * A sample reference for the settings preview.
     */
    public function preview(?int $tenantId = null): string
    {
        $code = app(AgentCodeService::class)->codeForTenant($tenantId) ?? AgentCodeService::FALLBACK_CODE;

        return $this->format(Carbon::now()->format('ym'), $code, 1);
    }

    protected function yearMonth(?int $tenantId, $createdAt = null): string
    {
        $time = $createdAt ?? Carbon::now();

        $tz = null;
        if ($tenantId !== null && $createdAt === null) {
            $tz = Tenant::whereKey($tenantId)->value('timezone');
        }

        return Carbon::parse($time, $tz ?: null)->format('ym');
    }

    protected function agentCodeFor(Lead $lead): string
    {
        if ($lead->agent_id !== null) {
            $code = User::whereKey($lead->agent_id)->value('agent_code');
            if ($code) {
                return $code;
            }
        }

        return $this->fallbackCode($lead->tenant_id);
    }

    protected function fallbackCode(?int $tenantId): string
    {
        if ($tenantId !== null) {
            $code = app(AgentCodeService::class)->codeForTenant($tenantId);
            if ($code) {
                return $code;
            }
        }

        return AgentCodeService::FALLBACK_CODE;
    }

    protected function nextSequence(?int $tenantId, string $yearMonth, string $agentCode): int
    {
        $prefix = $yearMonth . '-' . $agentCode . '-';

        $last = Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', $prefix . '%')
            ->max('reference');

        if ($last === null) {
            return 1;
        }

        $parts = explode('-', (string) $last);

        return ((int) end($parts)) + 1;
    }
}