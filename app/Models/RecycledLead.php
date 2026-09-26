<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class RecycledLead extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Where a recycled record came from.
     */
    public const SOURCES = [
        'csv_import' => 'Previous Portals Import',
        'api_import' => 'Portal API Import',
        'auto_recycle' => 'Auto-Recycled from Leads',
    ];

    /**
     * The UAE portals this feature is built for (slugs are also valid
     * realestate lead sources).
     */
    public const PORTALS = [
        'bayut' => 'Bayut',
        'dubizzle' => 'Dubizzle',
        'property_finder' => 'PropertyFinder',
    ];

    /**
     * How a portal lead first contacted us — Bayut report exports ship one
     * log per channel, so this is the channel the row came in on.
     */
    public const CATEGORIES = [
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'phone' => 'Phone',
        'email' => 'Email',
    ];

    /**
     * Working statuses while the contact sits in the pool.
     */
    public const STATUSES = [
        'pending' => 'Pending',
        'reached' => 'Reached',
        'not_reached' => 'Not Reached',
        'wrong_number' => 'Wrong Number',
        'not_interested' => 'Not Interested',
        'call_back' => 'Call Back Later',
        'do_not_contact' => 'Do Not Contact',
        'already_active' => 'Already an Active Lead',
        'regenerated' => 'Regenerated',
    ];

    /**
     * Regeneration intents (the reason to re-engage a recycled contact).
     * Each intent maps to a deal type, a lead contact type and whether it
     * tracks the handover of the buyer's purchased unit.
     */
    public const INTENTS = [
        'rental_relocation' => 'Still renting / relocation',
        'renter_to_buyer' => 'Renting now, wants to buy',
        'investment_opportunities' => 'Past buyer — new investment opportunities',
        'lease_purchased_unit' => 'Lease out their purchased unit',
        'handover_tracking' => 'Track handover of their nearly-ready unit',
    ];

    /**
     * Deal type each intent regenerates the lead with.
     */
    public const INTENT_DEAL_TYPES = [
        'rental_relocation' => 'rent',
        'renter_to_buyer' => 'sale',
        'investment_opportunities' => 'sale',
        'lease_purchased_unit' => 'rent',
        'handover_tracking' => 'rent',
    ];

    /**
     * Contact type stamped on the regenerated lead per intent.
     */
    public const INTENT_CONTACT_TYPES = [
        'rental_relocation' => 'buyer_lead',
        'renter_to_buyer' => 'buyer_lead',
        'investment_opportunities' => 'buyer_lead',
        'lease_purchased_unit' => 'seller_lead',
        'handover_tracking' => 'buyer_lead',
    ];

    /**
     * Intents that schedule a handover check-in task on regeneration.
     */
    public const HANDOVER_INTENTS = ['handover_tracking'];

    /**
     * WhatsApp outreach starters per intent (prefilled into wa.me links).
     */
    public const OUTREACH_SCRIPTS = [
        'rental_relocation' => 'Hello! We helped you find a rental on {portal} before. Have you relocated or are you still looking for a new place? We have fresh availability in {area} and would love to help.',
        'renter_to_buyer' => 'Hello! You rented through us on {portal} previously. Are you considering buying now? We have new investment opportunities and can guide you through it.',
        'investment_opportunities' => 'Hello! You bought a property through us before. We have new investment opportunities you might like — shall I send the latest details?',
        'lease_purchased_unit' => 'Hello! You purchased a unit through us before. Congratulations! If it is ready or nearly ready, we can help you lease it out or market it — how would you like to proceed?',
        'handover_tracking' => 'Hello! Your unit in {project} is approaching handover. We would like to stay in touch and help you lease it out or manage it once you take over — when is your expected handover?',
    ];

    protected $fillable = [
        'tenant_id',
        'source',
        'portal',
        'category',
        'reference',
        'first_name',
        'last_name',
        'phone',
        'normalized_phone',
        'whatsapp_username',
        'email',
        'normalized_email',
        'original_deal_type',
        'purchased_project',
        'unit_no',
        'gross_price',
        'expected_handover_date',
        'status',
        'call_notes',
        'call_recording_url',
        'last_contacted_at',
        'next_call_at',
        'assignee_id',
        'original_lead_id',
        'linked_lead_id',
        'regenerated_lead_id',
        'regeneration_intent',
        'created_by',
        'raw_data',
        'notes',
        'recycled_at',
        'lead_date',
        'needs_review',
        'review_reason',
        'email_verification_status',
        'email_verified_at',
        'email_verification_checked_at',
        'email_verification_message',
    ];

    protected function casts(): array
    {
        return [
            'gross_price' => 'decimal:2',
            'expected_handover_date' => 'date',
            'lead_date' => 'date',
            'last_contacted_at' => 'datetime',
            'next_call_at' => 'datetime',
            'recycled_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'email_verification_checked_at' => 'datetime',
            'raw_data' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function originalLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'original_lead_id');
    }

    public function linkedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'linked_lead_id');
    }

    public function regeneratedLead(): BelongsTo
    {
        return $this->belongsTo(Lead::class, 'regenerated_lead_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getPortalLabelAttribute(): string
    {
        return self::PORTALS[$this->portal] ?? ($this->portal ? ucfirst($this->portal) : '—');
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source] ?? $this->source;
    }

    public function getCategoryLabelAttribute(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }

    public function getDealTypeLabelAttribute(): string
    {
        return $this->original_deal_type === 'sale' ? 'Sales' : ($this->original_deal_type === 'rent' ? 'Rent' : '—');
    }

    /**
     * Whether the contact was a past buyer (sales) — drives the outreach angle.
     */
    public function isPastBuyer(): bool
    {
        return $this->original_deal_type === 'sale';
    }

    /**
     * The intent config for a regeneration, or null when invalid.
     *
     * @return array{key: string, label: string, deal_type: string, contact_type: string, tracks_handover: bool}|null
     */
    public static function intent(?string $key): ?array
    {
        if (! $key || ! isset(self::INTENTS[$key])) {
            return null;
        }

        return [
            'key' => $key,
            'label' => self::INTENTS[$key],
            'deal_type' => self::INTENT_DEAL_TYPES[$key],
            'contact_type' => self::INTENT_CONTACT_TYPES[$key],
            'tracks_handover' => in_array($key, self::HANDOVER_INTENTS, true),
        ];
    }

    /**
     * Outreach script template for an intent, with {placeholders} resolved.
     */
    public static function outreachScript(?string $key, array $replace = []): ?string
    {
        if (! $key || ! isset(self::OUTREACH_SCRIPTS[$key])) {
            return null;
        }

        $script = self::OUTREACH_SCRIPTS[$key];

        foreach ($replace as $token => $value) {
            $script = str_replace('{'.$token.'}', (string) $value, $script);
        }

        // Drop any unresolved placeholders for tidiness.
        return (string) preg_replace('/\{[a-z]+\}/i', '', $script);
    }

    /**
     * The contact's phone in the digits-only international form wa.me needs.
     * Mirrors Lead::getWhatsappPhoneAttribute so pool outreach behaves the same.
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
                $digits = substr($digits, 2);
            } else {
                $dialingCode = \App\Helpers\TenantFormatHelper::dialingCode();

                if ($dialingCode !== null) {
                    if (str_starts_with($digits, '0')) {
                        $digits = $dialingCode.ltrim(substr($digits, 1), '0');
                    } elseif (! str_starts_with($digits, $dialingCode)) {
                        $digits = $dialingCode.$digits;
                    }
                }
            }
        }

        return strlen($digits) >= 7 ? $digits : null;
    }
}
