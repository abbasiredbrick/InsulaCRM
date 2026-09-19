<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class A2aContract extends Model
{
    use HasFactory;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SENT = 'sent';
    public const STATUS_SIGNED = 'signed';
    public const STATUS_VOID = 'void';

    protected $fillable = [
        'tenant_id',
        'agent_id',
        'contract_number',
        'counterparty_name',
        'counterparty_email',
        'counterparty_company',
        'counterparty_address',
        'share_pct',
        'funding_source',
        'terms',
        'status',
        'sent_at',
        'signed_at',
        'signed_file_path',
        'signed_original_name',
    ];

    protected function casts(): array
    {
        return [
            'share_pct' => 'decimal:2',
            'sent_at' => 'datetime',
            'signed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // Every contract gets a stable, human-readable number unless supplied.
        static::creating(function (A2aContract $contract) {
            if (blank($contract->contract_number)) {
                $contract->contract_number = 'A2A-'.now()->format('Y').'-'.str_pad((string) ($contract->whereNotNull('id')->max('id') ?? 0) + 1, 4, '0', STR_PAD_LEFT);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function isSigned(): bool
    {
        return $this->status === self::STATUS_SIGNED;
    }

    public function getFundingLabelAttribute(): string
    {
        return match ($this->funding_source) {
            'from_agent' => __('Entirely from the main agent'),
            'from_company' => __('Entirely from the company'),
            'from_both' => __('Half company / half main agent'),
            default => '—',
        };
    }

    public function signedFileUrl(): ?string
    {
        if (! $this->signed_file_path) {
            return null;
        }

        return \Illuminate\Support\Facades\Storage::disk('local')->url($this->signed_file_path);
    }
}