<?php

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'lead_id',
        'deal_id',
        'agent_id',
        'type',
        'subject',
        'body',
        'subject_type',
        'subject_id',
        'logged_at',
    ];

    protected $casts = [
        'logged_at' => 'datetime',
        'subject_id' => 'integer',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);
    }

    /**
     * The scheduling entity (viewing / meeting / task) this activity links to.
     */
    public function subjectable()
    {
        return $this->morphTo('subject');
    }

    /**
     * Get the lead this activity belongs to.
     */
    public function lead()
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Get the deal this activity belongs to.
     */
    public function deal()
    {
        return $this->belongsTo(Deal::class);
    }

    /**
     * Get the agent who logged this activity.
     */
    public function agent()
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
