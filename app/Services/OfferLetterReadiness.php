<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\Tenant;

/**
 * What must be true before an offer letter may be created.
 *
 * An offer letter is the document a client signs to be held to a price, so the
 * things it prints have to exist before it is generated rather than after.
 * Nothing used to check this: a letter could be created, approved, printed and
 * signed with no company letterhead, no authorised signature, no seal and an
 * empty bank page, because `assetUrl()` degrades a missing file to `null`
 * instead of failing. The letter looked fine on screen and went out wrong.
 *
 * Two tiers on purpose:
 *
 *  - **Blockers** stop creation. Every one is fixable *outside* the create
 *    form (Settings, the lead, the deal), so gating on them can never trap an
 *    agent in a form they are not allowed to submit. That is the whole reason
 *    the unit picker and the contract value are not blockers: they are inputs
 *    of that form.
 *  - **Warnings** are printed on the letter as they are, and the agent is told.
 *    Refusing to issue a letter because the Emirates ID is blank would be
 *    worse than issuing it and saying so.
 *
 * Follows {@see \App\Services\Portals\BayutListingValidator}: an ordered spec
 * list, a per-deal walker, and a report of which rules failed rather than a
 * bare boolean.
 */
class OfferLetterReadiness
{
    /**
     * Where a blocker is fixed, so the message can link somewhere instead of
     * only complaining.
     */
    public const REMEDY_COMPANY = 'company';

    public const REMEDY_CLIENT = 'client';

    public const REMEDY_DEAL = 'deal';

    public const REMEDY_FORM = 'form';

    /**
     * @return array<int, array{key: string, label: string, remedy: string, test: \Closure}>
     */
    public function specs(Deal $deal): array
    {
        $tenant = $deal->tenant ?: new Tenant;
        $lead = $deal->lead;

        return [
            // ---- Company: printed on every letter, in the letterhead and again
            // in the signature block as "For <name>".
            [
                'key' => 'company.name',
                'label' => __('Company name (letterhead)'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => filled($tenant->name),
            ],
            [
                'key' => 'company.address',
                'label' => __('Company address (letterhead)'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => filled($tenant->address),
            ],
            [
                'key' => 'company.phone',
                'label' => __('Company phone (letterhead)'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => filled($tenant->phone),
            ],
            [
                'key' => 'company.email',
                'label' => __('Company email (letterhead)'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => filled($tenant->email),
            ],

            // A logo is deliberately NOT a blocker. `letterhead_display` can be
            // 'name' and the company can legitimately run a name-only
            // letterhead — OfferLetterFlowTest pins that as acceptable, and
            // the company name above is what the letter must never omit. A logo
            // is a warning, not a gate.

            // ---- Company: the marks of authority. Both print only once the
            // letter is approved, so a company without them ships an approved
            // letter that nobody has signed or sealed on the agency's behalf.
            [
                'key' => 'company.signature',
                'label' => __('Authorised signature'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => $this->assetReady($tenant->signature_path),
            ],
            [
                'key' => 'company.stamp',
                'label' => __('E-stamp / company seal'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => $this->assetReady($tenant->stamp_path),
            ],
            [
                // Either representation is a real IBAN instruction. Which one a
                // given letter uses is chosen per letter in the form, so both
                // are checked here rather than a single hard-coded one.
                'key' => 'company.iban',
                'label' => __('Bank / IBAN details (uploaded IBAN letter or typed details)'),
                'remedy' => self::REMEDY_COMPANY,
                'test' => fn (): bool => $this->assetReady($tenant->iban_letter_path)
                    || filled($tenant->bank_details),
            ],

            // ---- Client: the letter goes to this person, and they sign it.
            [
                'key' => 'client.name',
                'label' => __('Client / occupant name'),
                'remedy' => self::REMEDY_CLIENT,
                'test' => fn (): bool => filled($lead?->full_name),
            ],
            [
                'key' => 'client.email',
                // Validated, not merely present: the signing request is sent by
                // email, and a malformed address silently bounces.
                'label' => __('Client email address'),
                'remedy' => self::REMEDY_CLIENT,
                'test' => fn (): bool => filled($lead?->email)
                    && filter_var((string) $lead->email, FILTER_VALIDATE_EMAIL) !== false,
            ],
            [
                'key' => 'client.phone',
                'label' => __('Client phone number'),
                'remedy' => self::REMEDY_CLIENT,
                'test' => fn (): bool => filled($lead?->phone),
            ],

            // ---- Deal: what the letter is about.
            [
                'key' => 'deal.type',
                'label' => __('Deal type (rent or sale)'),
                'remedy' => self::REMEDY_DEAL,
                'test' => fn (): bool => filled($deal->deal_type),
            ],
        ];
    }

    /**
     * Non-blocking gaps worth saying out loud before the letter is printed.
     *
     * @return array<int, array{key: string, label: string, remedy: string}>
     */
    public function warningSpecs(Deal $deal): array
    {
        $warnings = [];

        if (blank($deal->tenant?->logo_path)) {
            $warnings[] = [
                'key' => 'company.logo',
                'label' => __('No company logo — the letterhead will print the company name only'),
                'remedy' => self::REMEDY_COMPANY,
            ];
        }

        if ($deal->dealUnit() === null) {
            // Not a blocker on purpose. The contract value is typed in the
            // create form, so a letter for an off-market property — one that is
            // not in inventory and therefore has no unit to link — is legitimate
            // and must not be refused. It just carries no property block.
            $warnings[] = [
                'key' => 'deal.unit_chosen',
                'label' => __('No unit linked to this deal — the letter will carry no property address or unit details'),
                'remedy' => app(OfferLetterService::class)->viewedUnits($deal)->isNotEmpty()
                    ? self::REMEDY_FORM
                    : self::REMEDY_DEAL,
            ];
        }

        // Standard on a UAE offer letter but not required to issue one.
        $warnings[] = [
            'key' => 'offer.emirates_id',
            'label' => __('Occupant Emirates ID is blank — the letter will not carry one'),
            'remedy' => self::REMEDY_FORM,
        ];

        $warnings[] = [
            'key' => 'offer.term',
            'label' => __('No tenancy term set — the letter will print no start or end date'),
            'remedy' => self::REMEDY_FORM,
        ];

        // Configured but unreadable. The letter still prints — a missing file
        // simply drops off the page — so this is a warning, not a blocker, but
        // it is exactly the case that produces an unsigned-looking offer, so an
        // admin needs to hear about it before it reaches a client.
        foreach ($this->unreadableAssets($deal) as $field) {
            $warnings[] = [
                'key' => 'asset.'.$field,
                'label' => __('The :field file is configured but cannot be read — the letter will print without it', [
                    'field' => str_replace('_path', '', $field),
                ]),
                'remedy' => self::REMEDY_COMPANY,
            ];
        }

        return $warnings;
    }

    /**
     * Blockers this deal currently fails.
     *
     * @return array<int, array{key: string, label: string, remedy: string}>
     */
    public function blockers(Deal $deal): array
    {
        $missing = [];

        foreach ($this->specs($deal) as $spec) {
            if (! ($spec['test'])()) {
                $missing[] = ['key' => $spec['key'], 'label' => $spec['label'], 'remedy' => $spec['remedy']];
            }
        }

        return $missing;
    }

    /**
     * Warnings that actually apply to this deal, rather than every warning we
     * could think of.
     *
     * @return array<int, array{key: string, label: string, remedy: string}>
     */
    public function warnings(Deal $deal): array
    {
        $applicable = [];

        foreach ($this->warningSpecs($deal) as $spec) {
            $passes = match ($spec['key']) {
                // Only nag about a term when the letter could actually carry one.
                'offer.term' => filled($deal->deal_type),

                // A name-only letterhead is a supported choice, so this is only
                // worth saying when the company asked for the logo.
                'company.logo' => filled($deal->tenant?->logo_path)
                    || ($deal->tenant?->letterhead_display ?? 'both') !== 'name',

                default => true,
            };

            if ($passes) {
                $applicable[] = $spec;
            }
        }

        return $applicable;
    }

    public function isReady(Deal $deal): bool
    {
        return $this->blockers($deal) === [];
    }

    /**
     * The gate message, or null when the letter may be created.
     *
     * Names every missing item rather than stopping at the first: an agent
     * fixing five things one round-trip at a time is a bad afternoon.
     */
    public function gateError(Deal $deal): ?string
    {
        $blockers = $this->blockers($deal);

        if ($blockers === []) {
            return null;
        }

        $labels = array_map(fn (array $b): string => $b['label'], $blockers);

        return __('Before an offer letter can be created, the following must be completed: :list', [
            'list' => implode(', ', $labels),
        ]);
    }

    /**
     * Where the agent should go to fix a blocker, grouped so the message can
     * point at one screen per area.
     *
     * @return array<string, string>
     */
    public function remedyLabels(): array
    {
        return [
            self::REMEDY_COMPANY => __('Company settings'),
            self::REMEDY_CLIENT => __('Client record'),
            self::REMEDY_DEAL => __('Deal'),
            self::REMEDY_FORM => __('This form'),
        ];
    }

    /**
     * A configured asset is one with a path AND a readable file.
     *
     * The file check is deliberately *not* part of the blocker: `assetUrl()`
     * already treats an unreadable file as absent, so a letter never breaks —
     * it just quietly prints without the mark. Gating on the stored path is
     * what stops the common case (never configured at all), and it keeps this
     * check free of filesystem state so it behaves the same everywhere.
     */
    protected function assetReady(?string $path): bool
    {
        return filled($path);
    }

    /**
     * Whether an asset is configured but its file cannot be read — the quieter
     * failure worth surfacing to an admin.
     */
    public function unreadableAssets(Deal $deal): array
    {
        $tenant = $deal->tenant;
        $broken = [];

        foreach (['logo_path', 'signature_path', 'stamp_path', 'iban_letter_path'] as $field) {
            $path = $tenant->{$field} ?? null;

            if (filled($path) && ! \Illuminate\Support\Facades\Storage::disk('public')->exists($path)) {
                $broken[] = $field;
            }
        }

        return $broken;
    }
}
