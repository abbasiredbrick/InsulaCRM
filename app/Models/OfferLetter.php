<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class OfferLetter extends Model
{
    /**
     * Lifecycle of an offer letter:
     *  - draft: being prepared, not yet issued to the client.
     *  - pending_approval: waiting for the agent's manager to approve it before
     *    it can be printed / sent to the client.
     *  - issued: approved by the manager and ready for the client to sign (or,
     *    when the creator is an admin/manager, approved immediately on creation).
     *  - signed: executed by the client and the signed copy uploaded. Only this
     *    status unlocks closing the transaction as Won.
     *  - declined: rejected by the client.
     *  - withdrawn: pulled back by our side before the client signed. Kept for
     *    the audit trail — anything the client may have seen is never deleted.
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'pending_approval' => 'Waiting for Approval',
        'issued' => 'Issued',
        'signed' => 'Signed',
        'declined' => 'Declined',
        'withdrawn' => 'Withdrawn',
    ];

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'lead_id',
        'offer_no',
        'status',
        'approved_by',
        'approved_at',
        'issued_at',
        'valid_until',
        'contract_start_date',
        'contract_end_date',
        'payment_period',
        'contract_years',
        'verification_token',
        'signature_request_token',
        'signature_requested_at',
        'signature_requested_by',
        'signature_request_expires_at',
        'signature_reminded_at',
        'signature_request_email',
        'occupant_signature_method',
        'occupant_signature_path',
        'occupant_signer_name',
        'occupant_signed_at',
        'occupant_signed_ip',
        'occupant_signed_ua',
        'occupant_content_hash',
        'bank_details_source',
        'bank_details',
        'occupant_name',
        'emirates_id',
        'security_deposit_mode',
        'rent_payable_to',
        'deposit_payable_to',
        'commission_payable_to',
        'admin_fee_payable_to',
        'contract_fee_payable_to',
        'documents_required',
        'original_amount',
        'discount_amount',
        'approved_amount',
        'discount_approved_by',
        'discount_approved_at',
        'commission_rate_pct',
        // 'percentage' of the contract value, or a stated 'value'.
        'commission_basis',
        // The VAT rate this letter was priced at — a snapshot of the tenant's
        // rate at the time, so a signed letter never silently re-prices when the
        // tenant setting changes. Zero when the tenant is not VAT registered.
        'commission_vat_pct',
        'commission_amount',
        'commission_vat',
        'commission_total',
        'security_deposit',
        'admin_fee',
        'admin_fee_vat',
        'admin_fee_total',
        'contract_fee',
        'contract_fee_vat',
        'contract_fee_total',
        'signed_pdf_path',
        'signed_at',
        'declined_at',
        'withdrawn_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'valid_until' => 'date',
            'contract_start_date' => 'date',
            'contract_end_date' => 'date',
            'contract_years' => 'integer',
            'original_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'commission_rate_pct' => 'decimal:2',
            'commission_vat_pct' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'commission_vat' => 'decimal:2',
            'commission_total' => 'decimal:2',
            'security_deposit' => 'decimal:2',
            'admin_fee' => 'decimal:2',
            'admin_fee_vat' => 'decimal:2',
            'admin_fee_total' => 'decimal:2',
            'contract_fee' => 'decimal:2',
            'contract_fee_vat' => 'decimal:2',
            'contract_fee_total' => 'decimal:2',
            'signed_at' => 'datetime',
            'signature_requested_at' => 'datetime',
            'signature_request_expires_at' => 'datetime',
            'signature_reminded_at' => 'datetime',
            'occupant_signed_at' => 'datetime',
            'declined_at' => 'datetime',
            'withdrawn_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    public function discountApprover()
    {
        return $this->belongsTo(User::class, 'discount_approved_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * The requests raised to change this letter's approved terms.
     */
    public function changeRequests()
    {
        return $this->hasMany(OfferLetterChangeRequest::class);
    }

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['issued', 'signed'], true);
    }

    /**
     * Whether this letter may be sent to the occupant for signature.
     *
     * Requires approval: sending an unapproved letter would have the client
     * agreeing to terms a manager has not seen, and the signature would then be
     * attached to terms that are still allowed to change.
     */
    public function canRequestSignature(): bool
    {
        return $this->isApproved() && ! $this->isSigned();
    }

    /**
     * Whether a signing link has been issued and is still usable.
     *
     * An expired link is treated as closed rather than as "send it again for
     * free": the occupant may already have read the letter, and silently
     * re-opening it would let a withdrawn-in-fact offer be agreed to late.
     */
    public function signatureRequestIsOpen(): bool
    {
        return filled($this->signature_request_token)
            && ! $this->signatureRequestIsExpired()
            && ! $this->isSigned();
    }

    public function signatureRequestIsExpired(): bool
    {
        return filled($this->signature_request_expires_at)
            && $this->signature_request_expires_at->isPast();
    }

    public function signatureRequestIsSent(): bool
    {
        return filled($this->signature_request_token);
    }

    /**
     * The occupant signed remotely rather than an agent scanning a copy.
     */
    public function wasSignedByOccupant(): bool
    {
        return filled($this->occupant_signed_at);
    }

    public function hasDiscount(): bool
    {
        return (float) $this->discount_amount > 0;
    }

    /**
     * Whether the commission was entered as a stated value rather than a
     * percentage of the contract value.
     */
    public function isCommissionOnValue(): bool
    {
        return $this->commission_basis === 'value';
    }

    /**
     * The VAT rate this letter carries, and zero when the tenant was not VAT
     * registered when it was written.
     */
    public function vatRate(): float
    {
        return (float) $this->commission_vat_pct;
    }

    public function chargesVat(): bool
    {
        return $this->vatRate() > 0;
    }

    /**
     * Every service the client pays us, VAT inclusive.
     *
     * These are the only lines that are ever VATable — the residential lease or
     * sale value above them is not.
     *
     * @return array<int, array{label: string, net: float, vat: float, total: float}>
     */
    public function serviceCharges(): array
    {
        $commissionNet = (float) $this->commission_amount;
        $commissionVat = (float) $this->commission_vat;

        $lines = [[
            'key' => 'commission',
            'label' => $this->isCommissionOnValue() ? __('Commission') : __('Commission @ :rate%', ['rate' => rtrim(rtrim(number_format((float) $this->commission_rate_pct, 2, '.', ''), '0'), '.')]),
            'net' => $commissionNet,
            'vat' => $commissionVat,
            'total' => (float) $this->commission_total,
        ]];

        foreach ([['admin_fee', __('Admin Fee')], ['contract_fee', __('Contract Fee')]] as [$column, $label]) {
            $net = (float) ($this->{$column} ?? 0);

            if ($net <= 0) {
                continue;
            }

            $lines[] = [
                'key' => $column,
                'label' => $label,
                'net' => $net,
                'vat' => (float) ($this->{$column.'_vat'} ?? 0),
                'total' => (float) ($this->{$column.'_total'} ?? $net),
            ];
        }

        return $lines;
    }

    /**
     * Every amount the tenant owes, as one list, each naming who collects it.
     *
     * The letter used to split these across two tables — the rent and deposit in
     * one, the agency's own charges in another — which read as two separate
     * obligations. They are one set of numbers, and what actually changes
     * between letters is who collects each line, so the table is built here with
     * the party carried per row rather than baked into which table it fell in.
     *
     * Rent and the deposit carry no VAT (a residential lease is not VATable in
     * the UAE), so they get zero rather than a hidden column.
     */
    public function payableLines(): array
    {
        $lines = [];
        $isRent = ($this->deal?->dealType() ?? 'rent') === 'rent';
        $lines = [];
        $tenantName = $this->tenant?->name ?? '';
        $landlordName = $this->deal?->property?->owner_name
            ?: ($isRent ? __('Landlord') : __('Seller'));

        if ($isRent) {
            $lines[] = [
                'key' => 'rent',
                'label' => __('Rental Amount').($this->paymentPeriodLabel() ? ' — '.$this->paymentPeriodLabel() : ''),
                'net' => (float) $this->approved_amount,
                'vat' => 0.0,
                'total' => (float) $this->approved_amount,
                'vat_charges' => false,
                'payee' => $this->payableToName('rent', $landlordName),
            ];

            if ((float) $this->security_deposit > 0) {
                $lines[] = [
                    'key' => 'deposit',
                    'label' => __('Security Deposit').($this->approved_amount > 0
                        ? ' ('.__('5% of annual rent').')' : ''),
                    'net' => (float) $this->security_deposit,
                    'vat' => 0.0,
                    'total' => (float) $this->security_deposit,
                    'vat_charges' => false,
                    'payee' => $this->payableToName('deposit', $landlordName),
                ];
            }
        } else {
            $lines[] = [
                'key' => 'rent',
                'label' => __('Sales Amount'),
                'net' => (float) $this->approved_amount,
                'vat' => 0.0,
                'total' => (float) $this->approved_amount,
                'vat_charges' => false,
                'payee' => $this->payableToName('rent', $landlordName),
            ];
        }

        foreach ($this->serviceCharges() as $charge) {
            $lines[] = [
                'key' => $charge['key'],
                'label' => $charge['label'],
                'net' => (float) $charge['net'],
                'vat' => (float) $charge['vat'],
                'total' => (float) $charge['total'],
                'vat_charges' => true,
                // Still the landlord is the fallback party: a service line routed
                // away from the agency is collected by the landlord, so naming
                // ourselves there would contradict the selection.
                'payee' => $this->payableToName($charge['key'], $landlordName),
            ];
        }

        return $lines;
    }

    /**
     * Who collects a line: the named party when they take it, the agency when
     * the letter routes it through us.
     */
    protected function payableToName(string $line, string $partyName): string
    {
        $key = $line.'_payable_to';

        return in_array($this->{$key}, ['landlord', 'broker'], true) && $this->{$key} === 'broker'
            ? ($this->tenant?->name ?? __('the Agency'))
            : $partyName;
    }

    /**
     * VAT charged across every service line, for the invoice summary.
     */
    public function totalVat(): float
    {
        return round(array_sum(array_column($this->serviceCharges(), 'vat')), 2);
    }

    /**
     * Figures may only change while nothing has been approved. Editing
     * deliberately locks out 'issued' so an approved offer can never be
     * silently re-priced behind the approver's back.
     */
    public function isEditable(): bool
    {
        return in_array($this->status, ['draft', 'pending_approval'], true);
    }

    /**
     * "1 Payment" / "4 Payments" for the printed letter.
     *
     * `payment_period` used to be a free-text box, so real letters hold values
     * like "1 Payment" or "2 Cheques". A plain numeric suffix on those would
     * read "1 Payment Payments", so anything that is not a bare 1-12 is printed
     * exactly as it was typed.
     */
    public function paymentPeriodLabel(): ?string
    {
        $raw = trim((string) $this->payment_period);

        if ($raw === '') {
            return null;
        }

        if (ctype_digit($raw) && $raw >= 1 && $raw <= 12) {
            return $raw.' '.($raw == 1 ? __('Payment') : __('Payments'));
        }

        return $raw;
    }

    /**
     * The five payable-to columns as a map, for the edit form.
     *
     * Falls back to the conventional default per line so an old letter written
     * before these columns existed still opens on a sane selection rather than
     * a blank one.
     */
    public function payableToAttributes(): array
    {
        $out = [];

        foreach (['rent', 'deposit', 'commission', 'admin_fee', 'contract_fee'] as $line) {
            $key = $line.'_payable_to';
            $out[$key] = in_array($this->{$key}, ['landlord', 'broker'], true)
                ? $this->{$key}
                : \App\Services\OfferLetterService::PAYABLE_DEFAULTS[$line];
        }

        return $out;
    }

    public function canWithdraw(): bool
    {
        return in_array($this->status, ['draft', 'pending_approval', 'issued'], true);
    }

    public function canDelete(): bool
    {
        return $this->isEditable();
    }

    /**
     * Whether a specific user may hard-delete this letter. Apart from the
     * Owner, the `isEditable()` rule stands: only a letter that has not yet
     * been approved may be deleted. The Owner can also clear a letter that has
     * already progressed (including a signed one), in which case the offer is
     * removed together with the activity and audit trail that mention it.
     */
    public function canDeleteBy(User $user): bool
    {
        return $user->isOwner() || $this->canDelete();
    }
}
