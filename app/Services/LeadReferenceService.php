<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;

class LeadReferenceService
{
    /**
     * Human-readable lead identifier: {CODE}{YY}{MM}{SEQ} e.g. AJ2609001.
     */
    public const FORMULA = '{CODE}{YY}{MM}{SEQ}';

    public function format(string $yearMonth, string $agentCode, int $sequence): string
    {
        return $agentCode . $yearMonth . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
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
        $prefix = $agentCode . $yearMonth;

        $max = 0;
        foreach (Lead::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('reference', 'like', $prefix . '%')
            ->pluck('reference') as $reference) {
            $tail = substr((string) $reference, strlen($prefix));
            if ($tail !== '' && ctype_digit($tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $max + 1;
    }
}