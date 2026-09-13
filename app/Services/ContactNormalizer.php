<?php

namespace App\Services;

use App\Helpers\TenantFormatHelper;

/**
 * Canonical forms for contact fields so that portal leads can be matched
 * against leads entered by hand, regardless of formatting differences
 * (spaces, dashes, country-code prefixes, case).
 */
class ContactNormalizer
{
    /**
     * Digits-only international form of a phone number.
     *
     * Mirrors the logic used for WhatsApp links: an explicitly international
     * number keeps its code, "00" is dropped as the international access prefix,
     * and a local number is promoted to the given country's dialing code.
     */
    public function phone(?string $phone, ?string $country = null): ?string
    {
        $raw = trim((string) $phone);

        if ($raw === '' || $raw === 'null' || str_contains($raw, 'y/')) {
            return null;
        }

        $isExplicitlyInternational = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $isExplicitlyInternational) {
            if (str_starts_with($digits, '00')) {
                $digits = substr($digits, 2);
            } else {
                $dialingCode = TenantFormatHelper::dialingCode($country);

                if ($dialingCode !== null) {
                    if (str_starts_with($digits, '0')) {
                        $digits = $dialingCode . ltrim(substr($digits, 1), '0');
                    } elseif (! str_starts_with($digits, $dialingCode)) {
                        $digits = $dialingCode . $digits;
                    }
                }
            }
        }

        return strlen($digits) >= 7 ? $digits : null;
    }

    /**
     * Canonical email: trimmed, lower-cased.
     */
    public function email(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return $email === '' ? null : $email;
    }

    public function samePhone(?string $a, ?string $b, ?string $country = null): bool
    {
        $x = $this->phone($a, $country);
        $y = $this->phone($b, $country);

        return $x !== null && $y !== null && $x === $y;
    }

    public function sameEmail(?string $a, ?string $b): bool
    {
        $x = $this->email($a);
        $y = $this->email($b);

        return $x !== null && $y !== null && $x === $y;
    }
}