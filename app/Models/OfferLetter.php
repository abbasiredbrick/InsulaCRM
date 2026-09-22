<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;

class OfferLetter extends Model
{
    /**
     * Lifecycle of an offer letter:
     *  - draft: being prepared, not yet issued to the client.
     *  - pending_approval: carries a discount that a manager/admin must approve.
     *  - issued: sent to the client (either at full price or with the approved
     *    discount).
     *  - signed: executed by the client and the signed copy uploaded. Only this
     *    status unlocks closing the transaction as Won.
     *  - declined: rejected by the client.
     */
    public const STATUSES = [
        'draft' => 'Draft',
        'pending_approval' => 'Pending Approval',
        'issued' => 'Issued',
        'signed' => 'Signed',
        'declined' => 'Declined',
    ];

    protected $fillable = [
        'tenant_id',
        'deal_id',
        'lead_id',
        'offer_no',
        'status',
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
        'commission_vat_pct',
        'commission_amount',
        'commission_vat',
        'commission_total',
        'security_deposit',
        'admin_fee',
        'tawtheeq_fee',
        'signed_pdf_path',
        'signed_at',
        'declined_at',
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
            'tawtheeq_fee' => 'decimal:2',
            'signed_at' => 'datetime',
            'declined_at' => 'datetime',
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

    public function isSigned(): bool
    {
        return $this->status === 'signed';
    }

    public function hasDiscount(): bool
    {
        return (float) $this->discount_amount > 0;
    }
}
