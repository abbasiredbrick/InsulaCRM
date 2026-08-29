<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'first_name',
        'last_name',
        'phone',
        'email',
        'lead_source',
        'campaign_id',
        'status',
        'contact_type',
        'temperature',
        'motivation_score',
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
            'custom_fields' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
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
                        $digits = $dialingCode . ltrim(substr($digits, 1), '0');
                    } elseif (! str_starts_with($digits, $dialingCode)) {
                        // A national number with no trunk prefix, as used in the
                        // NANP. Anything already carrying the code is left alone.
                        $digits = $dialingCode . $digits;
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

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function property()
    {
        return $this->hasOne(Property::class);
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

    public function tasks()
    {
        return $this->hasMany(Task::class);
    }

    public function deals()
    {
        return $this->hasMany(Deal::class);
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
