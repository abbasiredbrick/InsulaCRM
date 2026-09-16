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
    public const FALLBACK_CODE = 'NA';

    /**
     * Build a unique, stable two-letter agent code from the person's name.
     *
     * Primary candidate: first letter of the first and second name.
     * When that pair is already taken, we walk the same name's letters to
     * derive the next unused pair (e.g. JS → JO → JH → JN), only resorting
     * to a spare alphabetic pair when the name itself is exhausted.
     */
    public function generate(string $name): string
    {
        foreach ($this->candidates($name) as $candidate) {
            if (! User::where('agent_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        for ($i = 0; $i < 676; $i++) {
            $candidate = chr(65 + intdiv($i, 26)) . chr(65 + ($i % 26));
            if (! User::where('agent_code', $candidate)->exists()) {
                return $candidate;
            }
        }

        return 'ZZ';
    }

    /**
     * Ordered candidate two-letter pairs derived from a name, deduplicated.
     */
    protected function candidates(string $name): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', Str::ascii($name))));

        $firstWord = $words[0] ?? '';
        $a = $firstWord !== '' ? mb_substr($firstWord, 0, 1) : 'X';
        $b = isset($words[1]) && $words[1] !== '' ? mb_substr($words[1], 0, 1) : 'X';

        $candidates = [];
        $seen = [];

        $push = function (string $code) use (&$candidates, &$seen): void {
            $code = strtoupper($code);
            if (strlen($code) === 2 && ! isset($seen[$code])) {
                $seen[$code] = true;
                $candidates[] = $code;
            }
        };

        $push($a . $b);

        $pool = implode('', $words);
        foreach (array_slice(str_split($pool), 1) as $letter) {
            $push($a . $letter);
        }

        return $candidates;
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