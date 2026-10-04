<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Deal extends Model
{
    use HasFactory;

    /**
     * Wholesale stages constant — kept for backward compatibility.
     */
    public const STAGES = [
        'prospecting' => 'Prospecting',
        'contacting' => 'Contacting',
        'engaging' => 'Engaging',
        'offer_presented' => 'Offer Presented',
        'under_contract' => 'Under Contract',
        'dispositions' => 'Dispositions',
        'assigned' => 'Assigned',
        'closing' => 'Closing',
        'closed_won' => 'Closed Won',
        'closed_lost' => 'Closed Lost',
    ];

    /**
     * Probability (0–1) that a deal at this stage will convert to Closed Won.
     * Used for weighted pipeline forecast. Covers every stage key across the
     * leasing, wholesale and real-estate pipelines.
     */
    public const STAGE_PROBABILITIES = [
        // Leasing
        'new_lead' => 0.05,
        'outreach' => 0.1,
        'availability_shared' => 0.15,
        'viewing_requested' => 0.3,
        'viewing_scheduled' => 0.4,
        'viewing_done' => 0.45,
        'offer_requested' => 0.5,
        'offer_sent' => 0.55,
        'offer_signed' => 0.7,
        'deposit_received' => 0.8,
        'commission_received' => 0.9,
        'deal_won' => 0.95,
        'rent_paid' => 0.97,
        'tawtheeq_ejari' => 0.98,
        'move_in_permit' => 0.99,
        'moved_in' => 1.0,
        'deal_locked' => 1.0,
        // Wholesale
        'prospecting' => 0.1,
        'contacting' => 0.2,
        'engaging' => 0.3,
        'offer_presented' => 0.45,
        'dispositions' => 0.8,
        'assigned' => 0.9,
        'closing' => 0.95,
        // Real estate
        'lead' => 0.1,
        'listing_agreement' => 0.2,
        'active_listing' => 0.25,
        'showing' => 0.35,
        'offer_received' => 0.5,
        'inspection' => 0.8,
        'appraisal' => 0.85,
        // Shared
        'negotiating' => 0.6,
        'under_contract' => 0.75,
        'closed_won' => 1.0,
        'closed_lost' => 0.0,
    ];

    /**
     * Stages that mean the business was won.
     *
     * Leasing wins at `deal_won` — the point the broker's commission actually
     * lands — and wholesale/sale still win at `closed_won`. Anything that reports
     * won revenue MUST use isWon()/whereWon() rather than matching 'closed_won',
     * or rent deals silently drop out of goals, campaigns and commission totals.
     */
    public const WON_STAGES = ['closed_won', 'deal_won', 'deal_locked'];

    public const LOST_STAGES = ['closed_lost'];

    /**
     * Stages that must never be counted as live pipeline: won business is earned,
     * not upcoming. Everything after the win still belongs here — a lease between
     * deal_won and deal_locked owes a tawtheeq and a move-in permit, but none of
     * that is pipeline, and counting it would overstate forecast.
     */
    public const TERMINAL_STAGES = ['closed_won', 'deal_won', 'deal_locked', 'closed_lost'];

    /**
     * Stages the system sets on its own. They are valid and they appear on the
     * board, but they are not offered in the stage dropdown: `deal_won` is a
     * consequence of the commission being confirmed and `deal_locked` is a
     * consequence of the tenant moving in, so letting an agent pick them would let
     * the board claim money that never arrived.
     */
    public const SYSTEM_STAGES = ['deal_won', 'deal_locked'];

    /**
     * Stage that means the deal is won, for the given deal type. Leasing wins on
     * money received, not on a signature.
     */
    public static function wonStageFor(string $dealType): string
    {
        return $dealType === 'rent' ? 'deal_won' : 'closed_won';
    }

    public function isWon(): bool
    {
        return in_array($this->stage, self::WON_STAGES, true);
    }

    public function isLost(): bool
    {
        return in_array($this->stage, self::LOST_STAGES, true);
    }

    public function isTerminal(): bool
    {
        // Won or lost. Deliberately not "SYSTEM_STAGES": deal_won is decided
        // business even though the lease still owes registration steps, and the
        // board must not offer it as future revenue.
        return $this->isWon() || $this->isLost();
    }

    /**
     * Apply the won/lost scope to a deal query. The single place won revenue is
     * defined — do not hand-roll `where('stage','closed_won')`.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeWon($query)
    {
        return $query->whereIn('stage', self::WON_STAGES);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeLost($query)
    {
        return $query->whereIn('stage', self::LOST_STAGES);
    }

    /**
     * Leasing stages where the offer validity window is running: the signed
     * offer has not happened yet, so the offer stays valid until the deadline.
     */
    public const RENT_OFFER_VALIDITY_STAGES = ['offer_sent', 'negotiating'];

    /**
     * Leasing stages where the payment + Tawtheeq/Ejari registration deadline
     * (from offer_signed) applies: deposit must be collected and the contract
     * registered before the window lapses.
     */
    public const RENT_REGISTRATION_STAGES = ['offer_signed', 'deposit_received', 'tawtheeq_ejari'];

    /**
     * Default offer-validity / registration windows for rental transactions (days).
     */
    public const RENT_DEFAULT_VALIDITY_DAYS = 7;

    public const RENT_DEFAULT_REGISTRATION_DAYS = 7;

    /**
     * Weighted-forecast probability for a single stage key (defaults to 0.5).
     */
    public static function stageProbability(string $stage, ?Tenant $tenant = null): float
    {
        return (float) (self::STAGE_PROBABILITIES[$stage] ?? 0.5);
    }

    /**
     * Get pipeline stages for the current tenant's business mode.
     */
    public static function stages(?Tenant $tenant = null): array
    {
        return \App\Services\BusinessModeService::getStages($tenant);
    }

    /**
     * Leasing deal stages — mirrors Lead::LEASING_STAGES for the Transaction pipeline.
     */
    public static function leasingStages(): array
    {
        return Lead::LEASING_STAGES;
    }

    /**
     * Sale deal stages — realestate or wholesale depending on mode.
     */
    public static function saleStages(?Tenant $tenant = null): array
    {
        return \App\Services\BusinessModeService::getStages($tenant);
    }

    /**
     * Get the appropriate stage set for a given deal type.
     */
    public static function stagesForType(string $dealType, ?Tenant $tenant = null): array
    {
        return $dealType === 'rent'
            ? self::leasingStages()
            : self::saleStages($tenant);
    }

    /**
     * Get translated stage labels for the current tenant's business mode.
     */
    public static function stageLabels(?Tenant $tenant = null): array
    {
        return \App\Services\BusinessModeService::getStageLabels($tenant);
    }

    /**
     * Get translated label for a single stage.
     *
     * Falls back to the leasing / sale stage maps when the stage belongs to
     * a different deal type pipeline than the tenant's default mode pipeline.
     */
    public static function stageLabel(string $stage, ?Tenant $tenant = null): string
    {
        $label = \App\Services\BusinessModeService::getStageLabel($stage, $tenant);

        $modeStages = \App\Services\BusinessModeService::getStages($tenant);
        if (isset($modeStages[$stage])) {
            return $label;
        }

        $allStages = self::leasingStages() + self::saleStages($tenant);

        return isset($allStages[$stage]) ? __($allStages[$stage]) : $label;
    }

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'lease_id',
        'property_id',
        'deal_type',
        'agent_id',
        'title',
        'stage',
        'stage_changed_at',
        'contract_price',
        'assignment_fee',
        'earnest_money',
        'inspection_period_days',
        'contract_date',
        'due_diligence_end_date',
        'offer_sent_date',
        'offer_signed_date',
        'offer_validity_days',
        'registration_deadline_days',
        'closing_date',
        'listing_commission_pct',
        'buyer_commission_pct',
        'total_commission',
        'brokerage_split_pct',
        'mls_number',
        'listing_date',
        'days_on_market',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'deal_type' => 'string',
            'lease_id' => 'integer',
            'contract_price' => 'decimal:2',
            'assignment_fee' => 'decimal:2',
            'earnest_money' => 'decimal:2',
            'contract_date' => 'date',
            'due_diligence_end_date' => 'date',
            'offer_sent_date' => 'date',
            'offer_signed_date' => 'date',
            'offer_validity_days' => 'integer',
            'registration_deadline_days' => 'integer',
            'closing_date' => 'date',
            'listing_commission_pct' => 'decimal:2',
            'buyer_commission_pct' => 'decimal:2',
            'total_commission' => 'decimal:2',
            'brokerage_split_pct' => 'decimal:2',
            'listing_date' => 'date',
            'stage_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Deal $deal) {
            if (! $deal->stage_changed_at) {
                $deal->stage_changed_at = now();
            }
        });

        static::saving(function (Deal $deal) {
            $deal->applyStageAnchorsAndDueDiligence();
        });
    }

    /**
     * Auto-set the anchor dates for rental offer stages and recompute the
     * due-diligence / contingency deadline from the authoritative formula.
     *
     * Rental formula:
     *  - offer_sent / negotiating  -> offer_sent_date + offer_validity_days
     *  - offer_signed and beyond   -> offer_signed_date + registration_deadline_days
     * Sale/wholesale formula (unchanged):
     *  - under_contract            -> contract_date + inspection_period_days
     */
    public function applyStageAnchorsAndDueDiligence(): void
    {
        if ($this->is_leasing) {
            if ($this->stage === 'offer_sent' && ! $this->offer_sent_date) {
                $this->offer_sent_date = now()->startOfDay();
            }
            if ($this->stage === 'offer_signed' && ! $this->offer_signed_date) {
                $this->offer_signed_date = now()->startOfDay();
            }
        }

        if ($this->dueDiligenceApplies() && $this->computeDueDiligenceEndDate()) {
            $this->due_diligence_end_date = $this->computeDueDiligenceEndDate();
        }
    }

    /**
     * Whether a due-diligence / contingency deadline currently applies to this deal.
     */
    public function dueDiligenceApplies(): bool
    {
        if ($this->is_leasing) {
            return in_array($this->stage, self::RENT_OFFER_VALIDITY_STAGES, true)
                || in_array($this->stage, self::RENT_REGISTRATION_STAGES, true);
        }

        return $this->stage === 'under_contract';
    }

    /**
     * Compute the due-diligence / contingency deadline from the authoritative formula.
     */
    public function computeDueDiligenceEndDate(): ?Carbon
    {
        if ($this->is_leasing) {
            if (in_array($this->stage, self::RENT_OFFER_VALIDITY_STAGES, true) && $this->offer_sent_date) {
                return $this->offer_sent_date->copy()
                    ->addDays($this->offer_validity_days ?: self::RENT_DEFAULT_VALIDITY_DAYS);
            }

            if (in_array($this->stage, self::RENT_REGISTRATION_STAGES, true) && $this->offer_signed_date) {
                return $this->offer_signed_date->copy()
                    ->addDays($this->registration_deadline_days ?: self::RENT_DEFAULT_REGISTRATION_DAYS);
            }

            return null;
        }

        if ($this->stage === 'under_contract' && $this->contract_date && $this->inspection_period_days > 0) {
            return $this->contract_date->copy()->addDays($this->inspection_period_days);
        }

        return null;
    }

    /**
     * Human label for the current due-diligence / contingency window.
     */
    public function dueDiligencePeriodLabel(?Tenant $tenant = null): string
    {
        if ($this->is_leasing) {
            return in_array($this->stage, self::RENT_REGISTRATION_STAGES, true)
                ? __('Payment & Tawtheeq/Ejari deadline')
                : __('Offer validity');
        }

        return \App\Services\BusinessModeService::isRealEstate($tenant)
            ? __('Contingency deadline')
            : __('Due diligence deadline');
    }

    public function getDueDiligenceAppliesAttribute(): bool
    {
        return $this->dueDiligenceApplies();
    }

    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * The unit the client asked for an offer on, stamped from the viewing that
     * promoted the lead. This is the authoritative unit — `property()` below is a
     * fallback that walks through the lead, and it is frequently a different
     * unit from the one the offer was made on.
     */
    public function unit()
    {
        return $this->belongsTo(Property::class, 'property_id');
    }

    /**
     * The unit this deal is about: the offered unit when known, otherwise
     * whatever unit is linked to the lead.
     */
    public function dealUnit(): ?Property
    {
        return $this->unit ?: $this->property;
    }

    public function lease()
    {
        return $this->belongsTo(Lease::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function documents()
    {
        return $this->hasMany(DealDocument::class);
    }

    public function generatedDocuments()
    {
        return $this->hasMany(GeneratedDocument::class);
    }

    public function buyerMatches()
    {
        return $this->hasMany(DealBuyerMatch::class);
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function checklistItems()
    {
        return $this->hasMany(TransactionChecklist::class)->orderBy('sort_order');
    }

    public function offers()
    {
        return $this->hasMany(DealOffer::class);
    }

    public function offerLetters()
    {
        return $this->hasMany(OfferLetter::class)->orderByDesc('created_at');
    }

    public function property()
    {
        return $this->hasOneThrough(Property::class, Lead::class, 'id', 'lead_id', 'lead_id', 'id');
    }

    public function getDueDiligenceDaysRemainingAttribute(): ?int
    {
        if ($this->due_diligence_end_date) {
            return (int) now()->startOfDay()->diffInDays($this->due_diligence_end_date, false);
        }

        return null;
    }

    public function getIsDueDiligenceUrgentAttribute(): bool
    {
        $days = $this->due_diligence_days_remaining;

        return $days !== null && $days <= 2 && $days >= 0;
    }

    /**
     * Deal type for transaction pipelines: 'rent' (leasing) or 'sale'.
     *
     * Uses the stored deal_type first, then falls back to a linked lease
     * (always renting) or the linked lead's deal intent.
     */
    public function dealType(): string
    {
        if ($this->deal_type && in_array($this->deal_type, ['rent', 'sale'], true)) {
            return $this->deal_type;
        }

        if ($this->lease_id) {
            return 'rent';
        }

        if ($this->relationLoaded('lead') && $this->lead) {
            return $this->lead->dealType() === 'rent' ? 'rent' : 'sale';
        }

        return 'sale';
    }

    public function getIsLeasingAttribute(): bool
    {
        return $this->dealType() === 'rent';
    }
}
