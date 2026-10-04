<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Fleet of deal types a lead can follow.
     */
    public const DEAL_TYPES = [
        'rent' => 'Leasing',
        'sale' => 'Sales',
    ];

    /**
     * Predefined pipeline stages for a leasing deal.
     */
    public const LEASING_STAGES = [
        'new_lead' => 'New / Registered',
        'outreach' => 'Contacted (Call / SMS / WhatsApp / Email)',
        'availability_shared' => 'Availability Shared (Incl. Alternatives)',
        'viewing_requested' => 'Viewing Requested',
        'viewing_scheduled' => 'Viewing Scheduled (Owner Confirmed)',
        'viewing_done' => 'Unit Viewed',
        'offer_requested' => 'Offer Requested',
        'offer_sent' => 'Offer Sent',
        'negotiating' => 'Negotiating (Price / Payments / Deposit or PDC)',
        'offer_signed' => 'Offer Signed',
        'deposit_received' => 'Deposit / First Payment Received (Landlord)',
        'commission_received' => 'Commission Received (Broker)',
        'deal_won' => 'Deal Won (Money In)',
        'rent_paid' => 'Rent / PDC Paid',
        'tawtheeq_ejari' => 'Tawtheeq (ADGM/DARI) / Ejari (DLD)',
        'move_in_permit' => 'Move-In Permit Issued',
        'moved_in' => 'Moved In / Settled',
        'deal_locked' => 'Deal Locked (Closed — Do Not Modify)',
        // A lease can die at any point before the money lands. The sale ladder
        // ends with closed_lost for the same reason, and without it here a lost
        // lease has no stage to move to and the lead can only be closed by hand.
        'closed_lost' => 'Closed Lost',
    ];

    /**
     * Predefined pipeline stages for a sales deal.
     */
    public const SALES_STAGES = [
        'offer_sent' => 'Offer Sent',
        'negotiating' => 'Negotiating (Price / Terms)',
        'offer_accepted' => 'Offer Accepted',
        'spa_signed' => 'SPA Signed',
        'deed_transfer' => 'Transfer at ADREC / DARI / ADGM',
        'closed' => 'Closed',
    ];

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'reference',
        'first_name',
        'last_name',
        'phone',
        'email',
        'lead_source',
        'campaign_id',
        'status',
        'contact_type',
        'temperature',
        'deal_type',
        'stage',
        'stage_changed_at',
        'status_changed_at',
        'recycled_at',
        'expected_move_in_date',
        'motivation_score',
        'commission_amount',
        'ai_motivation_score',
        'do_not_contact',
        'timezone',
        'notes',
        'custom_fields',
    ];

    protected function casts(): array
    {
        return [
            'do_not_contact' => 'boolean',
            'motivation_score' => 'integer',
            'ai_motivation_score' => 'integer',
            'commission_amount' => 'decimal:2',
            'custom_fields' => 'array',
            'stage_changed_at' => 'datetime',
            'status_changed_at' => 'datetime',
            'recycled_at' => 'datetime',
            'expected_move_in_date' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // Default new leads to the tenant's timezone when none was provided.
        static::creating(function (Lead $lead) {
            if (! $lead->timezone && $lead->tenant_id) {
                $lead->timezone = \App\Models\Tenant::whereKey($lead->tenant_id)->value('timezone');
            }

            if ($lead->stage && ! $lead->stage_changed_at) {
                $lead->stage_changed_at = now();
            }

            if ($lead->status && ! $lead->status_changed_at) {
                $lead->status_changed_at = now();
            }

            if (blank($lead->reference)) {
                $lead->reference = app(\App\Services\LeadReferenceService::class)->generate($lead);
            }
        });

        // Keep stage_changed_at in sync whenever the stage moves.
        static::updating(function (Lead $lead) {
            if ($lead->isDirty('stage') && $lead->stage && ! $lead->stage_changed_at) {
                $lead->stage_changed_at = now();
            }

            // Keep status_changed_at in sync whenever the status changes.
            if ($lead->isDirty('status') && $lead->status) {
                $lead->status_changed_at = now();
            }

            // A reassignment changes the owning agent, so the lead reference
            // (agent code + per-agent sequence) is recomputed for the new agent.
            if ($lead->isDirty('agent_id') && $lead->agent_id !== null) {
                $lead->reference = app(\App\Services\LeadReferenceService::class)->regenerate($lead);
            }
        });
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    /**
     * Concise picker label for searchable client dropdowns. Portal leads
     * often arrive without a name, so the label falls back to phone / email
     * — otherwise those clients can never be found by typing.
     */
    public function pickerLabel(): string
    {
        $name = trim("{$this->first_name} {$this->last_name}");

        if ($name) {
            return $name;
        }

        return trim(implode(' · ', array_filter([
            $this->phone,
            $this->email,
        ]))) ?: __('Unnamed client');
    }

    /**
     * All valid pipeline stage keys across both deal types, used for validation.
     */
    public static function allStageKeys(): array
    {
        return array_merge(array_keys(self::LEASING_STAGES), array_keys(self::SALES_STAGES));
    }

    /**
     * Resolve the pipeline (rent or sale) this lead belongs to.
     *
     * Prefers the stored deal_type; otherwise it falls back to the intent of the
     * linked inventory unit and finally assumes leasing.
     */
    public function dealType(): string
    {
        if ($this->deal_type && in_array($this->deal_type, ['rent', 'sale'], true)) {
            return $this->deal_type;
        }

        $intent = $this->property?->intent;
        if ($intent === 'sale') {
            return 'sale';
        }

        return 'rent';
    }

    /**
     * The available pipeline stages for this lead's deal type.
     */
    public function stageOptions(): array
    {
        return $this->dealType() === 'sale' ? self::SALES_STAGES : self::LEASING_STAGES;
    }

    /**
     * Human-readable label for the lead's current stage.
     */
    public function stageLabel(): ?string
    {
        if (! $this->stage) {
            return null;
        }

        return $this->stageOptions()[$this->stage] ?? $this->stage;
    }

    /**
     * The lead's phone number in the digits-only international form wa.me needs.
     *
     * Numbers are stored however the user typed them, so the country code is
     * resolved from the tenant's country rather than assumed. Returns null when
     * there is no usable number, so callers can hide the action entirely.
     */
    public function getWhatsappPhoneAttribute(): ?string
    {
        $raw = trim((string) $this->phone);

        if ($raw === '') {
            return null;
        }

        $isExplicitlyInternational = str_starts_with($raw, '+');
        $digits = preg_replace('/\D+/', '', $raw);

        if ($digits === '') {
            return null;
        }

        if (! $isExplicitlyInternational) {
            if (str_starts_with($digits, '00')) {
                // 00 is the international access prefix; drop it.
                $digits = substr($digits, 2);
            } else {
                $dialingCode = \App\Helpers\TenantFormatHelper::dialingCode();

                if ($dialingCode !== null) {
                    if (str_starts_with($digits, '0')) {
                        // National trunk prefix: replace it with the country code.
                        $digits = $dialingCode.ltrim(substr($digits, 1), '0');
                    } elseif (! str_starts_with($digits, $dialingCode)) {
                        // A national number with no trunk prefix, as used in the
                        // NANP. Anything already carrying the code is left alone.
                        $digits = $dialingCode.$digits;
                    }
                }
            }
        }

        // Shortest realistic international number is 7 digits; below that the
        // input was not a phone number and a wa.me link would be nonsense.
        return strlen($digits) >= 7 ? $digits : null;
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    /**
     * Additional agents / external collaborators sharing this lead's record
     * and its commission. Only live (non-removed) participants are returned.
     */
    public function leadAgents()
    {
        return $this->hasMany(LeadAgent::class);
    }

    public function activeLeadAgents()
    {
        return $this->hasMany(LeadAgent::class)->where('status', LeadAgent::STATUS_ACTIVE);
    }

    /**
     * CRM users sharing the lead as co-agents (external collaborators are not
     * users, so they are excluded here).
     */
    public function coAgents()
    {
        return $this->activeLeadAgents()->whereNotNull('agent_id');
    }

    public function hasCoAgent(User $user): bool
    {
        return $this->coAgents()->where('agent_id', $user->id)->exists();
    }

    public function commissions()
    {
        return $this->hasMany(LeadCommission::class);
    }

    /**
     * Total commission earned across won deals (deal_won for a lease,
     * closed_won for a sale). Used as the auto-prefill basis for the lead's
     * commission amount.
     */
    public function closedDealsCommissionTotal(): ?float
    {
        $total = $this->deals()
            ->won()
            ->whereNotNull('total_commission')
            ->sum('total_commission');

        return $total > 0 ? (float) $total : null;
    }

    /**
     * Gross commission basis for this lead: the explicitly entered amount, or
     * the sum of closed deals' total_commission, or null.
     */
    public function commissionBasis(): ?float
    {
        if ($this->commission_amount !== null) {
            return (float) $this->commission_amount;
        }

        return $this->closedDealsCommissionTotal() ?? 0;
    }

    /**
     * True if an earned commission snapshot already exists (effectively locking
     * the split at close time).
     */
    public function hasCommissionSnapshot(): bool
    {
        return $this->commissions()->where('status', LeadCommission::STATUS_EARNED)->exists();
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function property()
    {
        return $this->hasOne(Property::class);
    }

    public function properties()
    {
        return $this->belongsToMany(Property::class, 'lead_property')
            ->withPivot('relation_type')
            ->withTimestamps();
    }

    public function campaign()
    {
        return $this->belongsTo(Campaign::class);
    }

    public function photos()
    {
        return $this->hasMany(LeadPhoto::class)->latest();
    }

    public function activities()
    {
        return $this->hasMany(Activity::class);
    }

    public function showings()
    {
        return $this->hasMany(Showing::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
    }

    public function offerLetters()
    {
        return $this->hasManyThrough(OfferLetter::class, Deal::class, 'lead_id', 'deal_id', 'id', 'id');
    }

    /**
     * Whether a signed offer letter exists for any of this lead's deals. A
     * signed (and uploaded) offer is required before the lead can be Won.
     */
    public function hasSignedOffer(): bool
    {
        return $this->offerLetters()->where('status', 'signed')->exists();
    }

    /**
     * The most recent signed offer letter across the lead's deals, if any.
     */
    public function signedOffer(): ?\App\Models\OfferLetter
    {
        return $this->offerLetters()->where('status', 'signed')->latest('signed_at')->first();
    }

    public function lists()
    {
        return $this->belongsToMany(LeadList::class, 'list_leads', 'lead_id', 'list_id');
    }

    public function sequenceEnrollments()
    {
        return $this->hasMany(SequenceEnrollment::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function getListCountAttribute(): int
    {
        return $this->lists()->count();
    }

    /**
     * Check if lead is on the DNC list.
     */
    public function isOnDncList(): bool
    {
        if ($this->do_not_contact) {
            return true;
        }

        return DoNotContact::where('tenant_id', $this->tenant_id)
            ->where(function ($q) {
                if ($this->phone) {
                    $q->orWhere('phone', $this->phone);
                }
                if ($this->email) {
                    $q->orWhere('email', $this->email);
                }
            })->exists();
    }
}
