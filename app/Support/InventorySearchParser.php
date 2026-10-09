<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

/**
 * Normalizes free-text property searches so "2BR", "2 br", "2BHK",
 * "2 bed" and "2 bedroom" all resolve to a bedrooms filter, while the
 * remaining words are matched against the usual text fields.
 */
class InventorySearchParser
{
    protected static array $textFields = [
        'marketing_title',
        'address',
        'community',
        'sub_community',
        'unit_no',
        'developer_name',
        'owner_name',
    ];

    public static function apply(Builder $query, ?string $term): void
    {
        $normalized = trim(mb_strtolower((string) $term));
        $normalized = preg_replace('/[\s,.\-\/]+/', ' ', $normalized) ?? '';

        if ($normalized === '') {
            return;
        }

        $bedrooms = null;

        // Catch "2br", "2 br", "2bhk", "2 bhk", "2 bed", "2 bedroom", "3BD"...
        if (preg_match_all('/\b(\d+)\s*(?:bedrooms?|beds?|bhk|br|bd)\b/', $normalized, $matches)) {
            $bedrooms = (int) $matches[1][0];
            foreach ($matches[0] as $full) {
                $normalized = str_replace($full, ' ', $normalized);
            }
        }

        // A bare small integer ("2 marina", "0", "5 bahria town") is treated as a
        // bedroom count so common queries resolve even without a BR/BHK suffix.
        if ($bedrooms === null) {
            foreach (preg_split('/\s+/', $normalized) as $token) {
                if (preg_match('/^\d{1,2}$/', $token) && (int) $token <= 6) {
                    $bedrooms = (int) $token;
                    $normalized = str_replace($token, ' ', $normalized);
                    break;
                }
            }
        }

        // "maid", "maids room", "maid's room", "+ maid" filter to units with a
        // maid's room — a sharpener for the customer who wants staff quarters.
        if (preg_match('/(?:^|\s)(?:maid(?:s|\'?s)?(?:\s+room)?|\+\s*m(?:aid|aids)?)(?=\s|$)/i', $normalized, $m)) {
            $query->where('maids_room', true);
            // Drop the token plus stray punctuation ("+", apostrophes) so the
            // remaining words search the usual text fields.
            $normalized = trim((string) preg_replace('/[^a-zA-Z0-9\s]/', ' ', str_replace($m[0], ' ', $normalized)));
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?? '');

        if ($bedrooms !== null) {
            $query->where('bedrooms', $bedrooms);
        }

        if ($normalized === '') {
            return;
        }

        foreach (preg_split('/\s+/', $normalized) as $word) {
            $word = trim($word);
            if ($word === '' || ! preg_match('/[a-z0-9]/i', $word)) {
                continue;
            }
            $query->where(function ($q) use ($word) {
                foreach (self::$textFields as $field) {
                    $q->orWhere($field, 'like', "%{$word}%");
                }
            });
        }
    }
}
