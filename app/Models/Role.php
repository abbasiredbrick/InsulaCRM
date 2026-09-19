<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    /**
     * Authority ranking. Anything not listed (agents, custom roles) sits at 1.
     * The Owner outranks the Admin, who outranks every operational role.
     */
    public const RANKS = [
        'owner' => 3,
        'admin' => 2,
    ];

    protected $fillable = ['name', 'display_name', 'is_system', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Authority rank of this role (Owner 3, Admin 2, everyone else 1).
     */
    public function rank(): int
    {
        return self::RANKS[$this->name] ?? 1;
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function permissions()
    {
        return $this->belongsToMany(Permission::class, 'role_permission');
    }

    /**
     * Check if this role has a specific permission.
     */
    public function hasPermission(string $key): bool
    {
        return $this->permissions->contains('key', $key);
    }
}
