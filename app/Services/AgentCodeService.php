<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;

class AgentCodeService
{
    /**
     * Default code used when a lead has no agent (or the agent has no code).
     */
    public const FALLBACK_CODE = 'NA00';

    /**
     * Build a unique, stable agent code from the person's name.
     *
     * Format: two initials (first letter of the first and last words) followed
     * by a two-digit discriminator, e.g. "AJ07" for Alice Johnson.
     */
    public function generate(string $name): string
    {
        $initials = $this->initials($name);

        for ($i = 0; $i <= 99; $i++) {
            $candidate = $initials . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            if (! User::where('agent_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        for ($i = 0; $i < 100; $i++) {
            $candidate = $initials . str_pad((string) random_int(0, 99), 2, '0', STR_PAD_LEFT);
            if (! User::where('agent_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $initials . 'XX';
    }

    /**
     * Initials: first letter of the first and second words of a name.
     */
    public function initials(string $name): string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return 'XX';
        }

        $words = array_values(array_filter(preg_split('/\s+/', Str::ascii($name))));

        $first = $words[0] ?? '';
        $second = $words[1] ?? '';

        $a = mb_substr($first, 0, 1);
        $b = $second !== '' ? mb_substr($second, 0, 1) : 'X';

        return strtoupper(($a ?: 'X') . $b);
    }

    /**
     * The routing code for a property listing: the assigned agent's code, or
     * the tenant fallback code when unassigned.
     */
    public function codeForProperty(Property $property): ?string
    {
        if ($property->assignedAgent?->agent_code) {
            return $property->assignedAgent->agent_code;
        }

        return $this->codeForTenant($property->tenant_id);
    }

    public function codeForTenant(?int $tenantId): ?string
    {
        if ($tenantId !== null) {
            $tenant = Tenant::whereKey($tenantId)->first();

            return $tenant?->defaultAgentCode();
        }

        return self::FALLBACK_CODE;
    }
}