<?php

namespace App\Services;

use App\Models\Property;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * RERA (Dubai) / Madhmoun (Abu Dhabi) listing-permit expiry: the single home
 * for which reminders to send, who receives them, and how an expiry is
 * classified for badges and the renewals list.
 *
 * A listing permit is expensive to re-issue and a lapsed permit drops the
 * unit off the portals, so this must never be re-inlined per call site.
 */
class PermitExpiryService
{
    /** Days-before-expiry the tenant is reminded by default. */
    public const DEFAULT_REMINDER_DAYS = [7, 3, 1];

    /**
     * The tenant's configured reminder offsets (days before expiry), parsed
     * from the notification preferences. Falls back to the default 7/3/1.
     *
     * @return array<int, int>
     */
    public static function reminderDaysFor(?Tenant $tenant): array
    {
        $days = self::parseReminderDays($tenant?->notification_preferences['permit_expiry_reminder_days'] ?? null);

        return $days ?: self::DEFAULT_REMINDER_DAYS;
    }

    /**
     * Normalise a raw "7,3,1" string / array into a sorted list of positive
     * day offsets. Empty when nothing valid was supplied.
     *
     * @param  mixed  $stored
     * @return array<int, int>
     */
    public static function parseReminderDays($stored): array
    {
        if (is_string($stored)) {
            $stored = explode(',', $stored);
        }

        return collect(is_array($stored) ? $stored : [])
            ->map(fn ($day) => (int) $day)
            ->filter(fn (int $day) => $day >= 1 && $day <= 365)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The furthest reminder offset — the "due soon" window for badges and the
     * renewals list. Defaults to 7 when the tenant has no configuration.
     */
    public static function windowFor(?Tenant $tenant): int
    {
        $days = self::reminderDaysFor($tenant);

        return $days ? max($days) : 7;
    }

    /**
     * Whole days from today until the permit expires. Negative once expired,
     * zero on the expiry day, null when no expiry is recorded.
     */
    public static function daysLeft(Property $property): ?int
    {
        if (! $property->permit_expires_at) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($property->permit_expires_at->copy()->startOfDay(), false);
    }

    /**
     * Expiry bucket: none (no date), expired, due (within the window) or ok.
     */
    public static function status(Property $property, ?Tenant $tenant): string
    {
        $days = self::daysLeft($property);

        if ($days === null) {
            return 'none';
        }

        if ($days < 0) {
            return 'expired';
        }

        return $days <= self::windowFor($tenant) ? 'due' : 'ok';
    }

    /**
     * Who should be told a permit is expiring: the unit's agent and their
     * manager; when the unit is unassigned, the tenant admins. Mirrors the
     * lease-expiry recipient rule.
     *
     * @return Collection<int, User>
     */
    public static function recipientsFor(Property $property, Tenant $tenant): Collection
    {
        $recipients = collect();

        $agent = $property->assignedAgent;

        if ($agent && $agent->is_active) {
            $recipients->push($agent);
        }

        $manager = $agent?->manager;
        if ($manager && $manager->is_active && $manager->id !== $agent?->id) {
            $recipients->push($manager);
        }

        if ($property->assigned_agent_id === null) {
            $adminRoleIds = Role::whereIn('name', ['owner', 'admin'])->pluck('id');
            $recipients = $recipients->merge(
                User::where('tenant_id', $tenant->id)
                    ->whereIn('role_id', $adminRoleIds)
                    ->where('is_active', true)
                    ->get(),
            );
        }

        return $recipients->unique('id')->values();
    }
}
