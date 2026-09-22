<?php

namespace App\Services;

use App\Models\Deal;
use App\Models\OfferLetter;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Prepares, validates and renders brokerage offer letters.
 *
 * An offer letter is generated once a client likes a unit and the
 * PM / landlord finalises the price. It documents the final value (with any
 * manager-approved discount), the tenant's fees and the agency commission, and
 * is printable for the client to sign. A signed, uploaded copy is the
 * prerequisite for closing the transaction as Won.
 *
 * The offering party is the tenant itself (the brokerage / property management
 * company), so the tenant record's contact fields drive the letter header.
 */
class OfferLetterService
{
    public const OFFER_PREFIX = 'OL';

    /**
     * Default amounts/fees for an offer drafted against a deal, before the user
     * edits them.
     */
    public function buildDefaults(Deal $deal): array
    {
        $deal->loadMissing(['lead', 'lead.property', 'tenant']);

        $commission = app(DealCommissionService::class);
        $gross = $commission->grossFor($deal);
        $rate = $commission->rateFor($deal);
        $tenants = $commission->rates($deal->tenant);
        $property = $deal->property ?: $deal->lead?->property;
        $lead = $deal->lead;

        $start = $lead?->expected_move_in_date;
        $end = $start ? $start->copy()->addYear()->subDay() : null;

        $securityDeposit = $property?->deposit_amount;

        if ($deal->dealType() === 'rent' && ! $securityDeposit && $gross > 0) {
            $securityDeposit = round($gross * 0.05, 2);
        }

        return [
            'deal_type' => $deal->dealType(),
            'original_amount' => $gross,
            'approved_amount' => $gross,
            'commission_rate_pct' => $rate,
            'commission_vat_pct' => (float) $tenants['vat'],
            'commission_amount' => $commission->commissionFor($deal, $gross),
            'commission_vat' => $commission->vatFor($deal, $commission->commissionFor($deal, $gross), $deal->tenant),
            'commission_total' => $commission->commissionFor($deal, $gross) + $commission->vatFor($deal, $commission->commissionFor($deal, $gross), $deal->tenant),
            'security_deposit' => $securityDeposit ?: null,
            'admin_fee' => $property?->admin_fee,
            'tawtheeq_fee' => $property?->tawtheeq_fee,
            'payment_period' => '1 '.__('Payment'), // "1 Payment Payable to the Landlord"
            'contract_start_date' => $start,
            'contract_end_date' => $end,
            'documents_required' => 'Passport, Residency Visa & Emirates ID Copy',
            'valid_until' => now()->addDay()->startOfDay(),
            'offer_no' => $this->nextOfferNo($deal->tenant),
        ];
    }

    /**
     * Create an offer letter from validated input.
     *
     * A discount larger than zero requires manager/admin approval: the offer
     * is created as 'pending_approval' until an admin or manager approves it
     * (holders of the owner/admin role, or the deal lead's manager chain).
     */
    public function createFromValidated(Deal $deal, User $user, array $v): OfferLetter
    {
        $tenant = $deal->tenant;
        $commission = app(DealCommissionService::class);

        $original = (float) $v['original_amount'];
        $discount = max(0.0, (float) ($v['discount_amount'] ?? 0));
        $approved = max(0.0, round($original - $discount, 2));

        $rate = (float) ($v['commission_rate_pct'] ?? $commission->rateFor($deal));
        $gross = $approved > 0 ? $approved : $original;

        $commissionNet = round($gross * ($rate / 100), 2);
        $vatPct = (float) ($v['commission_vat_pct'] ?? $tenant->commissionRateSettings()['vat']);
        $vat = round($commissionNet * ($vatPct / 100), 2);

        $status = 'issued';
        $approvedBy = null;
        $approvedAt = null;

        if ($discount > 0) {
            if ($this->canApproveDiscount($user)) {
                $approvedBy = $user->id;
                $approvedAt = now();
                $status = 'issued';
            } else {
                $status = 'pending_approval';
            }
        }

        return DB::transaction(function () use ($deal, $tenant, $user, $v, $original, $discount, $approved, $rate, $vatPct, $commissionNet, $vat, $status, $approvedBy, $approvedAt) {
            $offer = OfferLetter::create([
                'tenant_id' => $tenant->id,
                'deal_id' => $deal->id,
                'lead_id' => $deal->lead_id,
                'offer_no' => (string) ($v['offer_no'] ?? '') ?: $this->nextOfferNo($tenant),
                'status' => $status,
                'issued_at' => now(),
                'valid_until' => $v['valid_until'] ?? now()->addDay()->startOfDay(),
                'contract_start_date' => $v['contract_start_date'] ?? null,
                'contract_end_date' => $v['contract_end_date'] ?? null,
                'payment_period' => $v['payment_period'] ?? null,
                'documents_required' => $v['documents_required'] ?? null,
                'original_amount' => $original,
                'discount_amount' => $discount,
                'approved_amount' => $approved,
                'discount_approved_by' => $approvedBy,
                'discount_approved_at' => $approvedAt,
                'commission_rate_pct' => $rate,
                'commission_vat_pct' => $vatPct,
                'commission_amount' => $commissionNet,
                'commission_vat' => $vat,
                'commission_total' => $commissionNet + $vat,
                'security_deposit' => $v['security_deposit'] ?? null,
                'admin_fee' => $v['admin_fee'] ?? null,
                'tawtheeq_fee' => $v['tawtheeq_fee'] ?? null,
                'notes' => $v['notes'] ?? null,
            ]);

            \App\Models\Activity::create([
                'tenant_id' => $tenant->id,
                'lead_id' => $deal->lead_id,
                'deal_id' => $deal->id,
                'agent_id' => $user->id,
                'type' => 'note',
                'subject' => __('Offer letter issued'),
                'body' => __('Offer letter :no issued for :amount (:status).', [
                    'no' => $offer->offer_no,
                    'amount' => \App\Helpers\TenantFormatHelper::currency($approved),
                    'status' => __(\App\Models\OfferLetter::STATUSES[$status] ?? $status),
                ]),
                'logged_at' => now(),
            ]);

            return $offer;
        });
    }

    /**
     * Approve the discount on a pending offer (manager/admin only).
     */
    public function approveDiscount(OfferLetter $offer, User $approver): OfferLetter
    {
        if ($offer->status !== 'pending_approval') {
            return $offer;
        }

        if (! $this->canApproveDiscount($approver)) {
            throw new \RuntimeException(__('Only an admin or manager can approve an offer discount.'));
        }

        $offer->update([
            'status' => 'issued',
            'discount_approved_by' => $approver->id,
            'discount_approved_at' => now(),
        ]);

        return $offer;
    }

    /**
     * Record that the client signed the letter. The signed copy must have been
     * uploaded before the letter can be signed.
     */
    public function markSigned(OfferLetter $offer, ?string $signedPdfPath = null, ?User $user = null): OfferLetter
    {
        if ($offer->status === 'pending_approval') {
            throw new \RuntimeException(__('The discount must be approved before this offer can be signed.'));
        }

        $offer->update([
            'status' => 'signed',
            'signed_at' => now(),
            'signed_pdf_path' => $signedPdfPath ?: $offer->signed_pdf_path,
        ]);

        if ($user && $offer->deal) {
            \App\Models\Activity::create([
                'tenant_id' => $offer->tenant_id,
                'lead_id' => $offer->deal->lead_id,
                'deal_id' => $offer->deal_id,
                'agent_id' => $user->id,
                'type' => 'note',
                'subject' => __('Offer letter signed'),
                'body' => __('Offer letter :no was signed by the client on :date.', [
                    'no' => $offer->offer_no,
                    'date' => $offer->signed_at->format('M j, Y'),
                ]),
                'logged_at' => now(),
            ]);
        }

        return $offer->refresh();
    }

    /**
     * Whether a user may approve a discount (admin or manager).
     */
    public function canApproveDiscount(User $user): bool
    {
        return $user->isAdmin() || $user->isManager();
    }

    /**
     * Render the printable offer letter HTML.
     */
    public function render(OfferLetter $offer): string
    {
        $offer->loadMissing(['tenant', 'deal.lead', 'deal.lead.property', 'discountApprover']);
        $deal = $offer->deal;
        $lead = $deal?->lead;
        $property = $deal?->lead?->property;
        $tenant = $offer->tenant;

        return view('offers.letter', compact('offer', 'deal', 'lead', 'property', 'tenant'))->render();
    }

    /**
     * Next offer number for the tenant, e.g. OL-0007.
     */
    public function nextOfferNo(Tenant $tenant): string
    {
        $last = OfferLetter::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id')
            ->value('offer_no');

        $seq = $last ? ((int) preg_replace('/\D+/', '', (string) $last)) + 1 : 1;

        return self::OFFER_PREFIX.'-'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Amount in English words, e.g. "One Hundred Twelve Thousand".
     */
    public function amountInWords($number, string $currency = ''): string
    {
        $number = round((float) $number, 2);
        $whole = (int) floor($number);
        $decimal = (int) round(($number - $whole) * 100);

        $out = trim($this->convertNumber($whole));

        if ($decimal > 0) {
            $out .= ' and '.$this->convertNumber($decimal);
        }

        if ($currency) {
            $out .= rtrim(rtrim(sprintf(' %s', $currency), '0'), '.');
        }

        return $out;
    }

    protected function convertNumber(int $number): string
    {
        if ($number === 0) {
            return 'Zero';
        }

        $words = '';
        if ($number < 0) {
            return 'Minus '.$this->convertNumber(abs($number));
        }

        $units = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen', ];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        if ($number < 20) {
            $words = $units[$number];
        } elseif ($number < 100) {
            $words = trim($tens[intdiv($number, 10)].' '.$units[$number % 10]);
        } elseif ($number < 1000) {
            $words = trim($units[intdiv($number, 100)].' Hundred '.$this->convertNumber($number % 100));
        } elseif ($number < 1000000) {
            $words = trim($this->convertNumber(intdiv($number, 1000)).' Thousand '.$this->convertNumber($number % 1000));
        } elseif ($number < 1000000000) {
            $words = trim($this->convertNumber(intdiv($number, 1000000)).' Million '.$this->convertNumber($number % 1000000));
        } else {
            $words = trim($this->convertNumber(intdiv($number, 1000000000)).' Billion '.$this->convertNumber($number % 1000000000));
        }

        return $words;
    }
}
