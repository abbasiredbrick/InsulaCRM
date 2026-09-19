<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'tenant_id',
        'role_id',
        'reports_to',
        'name',
        'email',
        'password',
        'is_active',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'two_factor_enabled',
        'two_factor_provider',
        'onboarding_completed',
        'theme',
        'calendar_feed_token',
        'email_from_name',
        'email_reply_to',
        'email_mode',
        'dashboard_widgets',
        'notification_delivery',
        'agent_code',
        'photo_storage',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'calendar_feed_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'two_factor_enabled' => 'boolean',
            'onboarding_completed' => 'boolean',
            'dashboard_widgets' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // TenantScope is not applied to User because the auth guard must load
        // the user before any scope can resolve auth()->user(), which would
        // cause infinite recursion and memory exhaustion.

        static::creating(function (User $user) {
            if (blank($user->agent_code)) {
                $user->agent_code = app(\App\Services\AgentCodeService::class)->generate((string) $user->name);
            }
        });
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function cloudConnections()
    {
        return $this->hasMany(UserCloudConnection::class, 'user_id');
    }

    public function calendarConnections()
    {
        return $this->cloudConnections()->where('scope', 'calendar');
    }

    public function driveConnections()
    {
        return $this->cloudConnections()->where('scope', 'drive');
    }

    /**
     * Whether the user has a working calendar connection (Google, Microsoft,
     * or an active iCal feed subscription as the "other" option).
     */
    public function hasCalendarConnection(): bool
    {
        if ($this->calendarConnections()->exists()) {
            return true;
        }

        return ! blank($this->calendar_feed_token);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    /**
     * Additional operational roles layered on top of the primary role_id. The
     * primary role drives rank, identity and the UI; secondary roles grant
     * extra capabilities (e.g. a Listing Agent who also cold calls).
     */
    public function secondaryRoles()
    {
        return $this->belongsToMany(Role::class, 'role_user', 'user_id', 'role_id')
            ->withTimestamps();
    }

    /**
     * Name of the primary role plus every additional role.
     */
    public function roleNames(): array
    {
        $names = $this->role ? [$this->role->name] : [];

        foreach ($this->secondaryRoles as $role) {
            $names[] = $role->name;
        }

        return array_values(array_unique(array_filter($names)));
    }

    // ── Team management ────────────────────────────────────────

    public function manager()
    {
        return $this->belongsTo(User::class, 'reports_to');
    }

    /**
     * This member's agreed compensation plan (split + pay structure).
     */
    public function commissionPlan()
    {
        return $this->hasOne(AgentCompensation::class);
    }

    public function directReports()
    {
        return $this->hasMany(User::class, 'reports_to');
    }

    /**
     * Whether this user manages at least one person (direct report).
     */
    public function isManager(): bool
    {
        return $this->directReports()->exists();
    }

    /**
     * Everyone under this user in the reporting chain (recursive, excluding self).
     */
    public function allReports(): \Illuminate\Support\Collection
    {
        $all = collect();
        $seen = collect([$this->id]);
        $queue = $this->directReports()->with('role')->get();

        while ($queue->isNotEmpty()) {
            $member = $queue->shift();
            if ($seen->contains($member->id)) {
                continue;
            }
            $seen->push($member->id);
            $all->push($member);
            $queue = $queue->merge($member->directReports()->with('role')->get());
        }

        return $all;
    }

    /**
     * IDs of every user in this manager's team (recursive, excluding self).
     */
    public function teamUserIds(): array
    {
        return $this->allReports()->pluck('id')->all();
    }

    /**
     * Whether the given user reports directly or indirectly to this user.
     */
    public function managesUser(?User $user): bool
    {
        return $user !== null
            && $user->id !== $this->id
            && in_array($user->id, $this->teamUserIds(), true);
    }

    /**
     * Whether this user is a manager of the agent that owns the lead, or an admin.
     */
    public function managesLead(\App\Models\Lead $lead): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return $lead->agent_id !== null
            && $this->isManager()
            && in_array($lead->agent_id, $this->teamUserIds(), true);
    }

    /**
     * Every manager above this user, closest first (recursive up the tree).
     */
    public function managerChain(): array
    {
        $chain = [];
        $seen = [];
        $current = $this->manager;

        while ($current !== null && ! in_array($current->id, $seen, true)) {
            $seen[] = $current->id;
            $chain[] = $current;
            $current = $current->manager;
        }

        return $chain;
    }

    public function hasRole(string $roleName): bool
    {
        if (! $this->role) {
            return $this->secondaryRoles()->where('name', $roleName)->exists();
        }

        $primary = $this->role->name;

        if ($primary === $roleName) {
            return true;
        }

        // The Owner is a strict superset of the Admin, so every existing
        // hasRole('admin') gate (route middleware included) admits them too.
        if ($roleName === 'admin' && $primary === 'owner') {
            return true;
        }

        // Owner and Admin are privilege roles that can only ever be the primary
        // role, so a secondary assignment of either is meaningless.
        if ($roleName === 'owner' || $roleName === 'admin') {
            return false;
        }

        return $this->secondaryRoles()->where('name', $roleName)->exists();
    }

    /**
     * Authority rank of this user's role (Owner 3, Admin 2, others 1).
     */
    public function roleRank(): int
    {
        return $this->role?->rank() ?? 0;
    }

    /**
     * Whether this user sits strictly above the given user in the hierarchy.
     */
    public function outranks(?User $other): bool
    {
        return $other !== null && $this->roleRank() > $other->roleRank();
    }

    /**
     * Users who may be assigned ownership of a tenant's leads, deals and tasks.
     *
     * Covers every system role valid for the tenant's business mode - admin
     * included - plus any custom role the tenant defined itself. Restricting
     * this to non-admin system roles previously left single-user tenants with
     * nobody to assign work to.
     */
    public function scopeAssignable($query, Tenant $tenant)
    {
        $roleNames = \App\Services\BusinessModeService::getAssignableRoleNames($tenant);

        $roleIds = Role::where(function ($q) use ($tenant, $roleNames) {
            $q->where(function ($q2) use ($roleNames) {
                $q2->where('is_system', true)->whereIn('name', $roleNames);
            })->orWhere('tenant_id', $tenant->id);
        })->pluck('id');

        return $query->where('tenant_id', $tenant->id)->whereIn('role_id', $roleIds);
    }

    /**
     * Check if user has a specific permission via their role.
     */
    public function hasPermission(string $key): bool
    {
        // Owner and Admin system roles always have all permissions.
        if ($this->role && $this->role->is_system && in_array($this->role->name, ['owner', 'admin'], true)) {
            return true;
        }

        // The primary role's own permissions…
        if ($this->role && $this->role->hasPermission($key)) {
            return true;
        }

        // …plus anything granted by an additional role.
        return $this->secondaryRoles->contains(fn (Role $role) => $role->hasPermission($key));
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function isAdmin(): bool
    {
        // hasRole('admin') is owner-inclusive: the Owner holds full rights.
        return $this->hasRole('admin');
    }

    public function isAgent(): bool
    {
        // Cold Call Agents are agents too; the role may be a primary or an
        // additional assignment.
        return $this->hasRole('agent') || $this->isColdCallAgent()
            || $this->isAcquisitionAgent()
            || $this->isListingAgent() || $this->isBuyersAgent();
    }

    public function isColdCallAgent(): bool
    {
        return $this->hasRole('cold_call_agent');
    }

    public function isAcquisitionAgent(): bool
    {
        return $this->hasRole('acquisition_agent');
    }

    public function isDispositionAgent(): bool
    {
        return $this->hasRole('disposition_agent');
    }

    public function isFieldScout(): bool
    {
        return $this->hasRole('field_scout');
    }

    public function isListingAgent(): bool
    {
        return $this->hasRole('listing_agent');
    }

    public function isBuyersAgent(): bool
    {
        return $this->hasRole('buyers_agent');
    }

    /**
     * Check if user can access lead management.
     */
    public function canManageLeads(): bool
    {
        return $this->isAdmin() || $this->isAgent() || $this->isAcquisitionAgent() || $this->isListingAgent();
    }

    /**
     * Check if user can access buyer/client database.
     */
    public function canManageBuyers(): bool
    {
        return $this->isAdmin() || $this->isDispositionAgent() || $this->isBuyersAgent();
    }
}
