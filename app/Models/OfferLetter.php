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

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function isApproved(): bool
    {
        return in_array($this->status, ['issued', 'signed'], true);
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
                'label' => $label,
                'net' => $net,
                'vat' => (float) ($this->{$column.'_vat'} ?? 0),
                'total' => (float) ($this->{$column.'_total'} ?? $net),
            ];
        }

        return $lines;
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

    public function canWithdraw(): bool
    {
        return in_array($this->status, ['draft', 'pending_approval', 'issued'], true);
    }

    public function canDelete(): bool
    {
        return $this->isEditable();
    }
}
