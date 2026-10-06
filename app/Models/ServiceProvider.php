<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceProvider extends Model
{
    public const STATUSES = [
        'pending' => 'Pending',
        'changes_requested' => 'Changes Requested',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
    ];

    public const CATEGORIES = [
        'cleaning' => 'Cleaning & Pest',
        'internet' => 'Internet/TV/Phone',
        'maintenance' => 'Maintenance & Repair',
        'moving' => 'Moving',
        'security' => 'Security',
        'landscaping' => 'Landscaping',
        'hvac' => 'HVAC',
        'utilities' => 'Utilities',
        'other' => 'Other',
    ];

    protected $fillable = [
        'tenant_id', 'status', 'category', 'company_name', 'trade_license_number',
        'services_offered', 'representative_name', 'representative_emirates_id',
        'email', 'mobile', 'city', 'website', 'edit_token', 'registration_link_id',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
    ];

    protected $casts = [
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ServiceProviderDocument::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ServiceProviderReview::class)->latest();
    }

    public function registrationLink(): BelongsTo
    {
        return $this->belongsTo(ServiceProviderRegistrationLink::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isUnderReview(): bool
    {
        return in_array($this->status, ['pending', 'changes_requested'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function categoryLabel(): string
    {
        return self::CATEGORIES[$this->category] ?? $this->category;
    }
}
