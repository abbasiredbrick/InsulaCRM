<?php

namespace App\Console\Commands;

use App\Models\Lease;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\LeaseExpiringReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class RemindExpiringLeases extends Command
{
    protected $signature = 'leases:remind-expiring {--window=45 : Days before expiry to fire the reminder}';

    protected $description = 'Notify agents and their managers when a lease contract is about to expire';

    public function handle(): int
    {
        $window = max(1, (int) $this->option('window'));

        $leases = Lease::withoutGlobalScopes()
            ->whereIn('status', ['active', 'renewed'])
            ->whereNull('reminder_sent_at')
            ->whereBetween('contract_end_date', [
                now()->startOfDay(),
                now()->startOfDay()->addDays($window),
            ])
            ->with(['buyer', 'lead', 'agent', 'tenant'])
            ->get();

        $notified = 0;
        foreach ($leases as $lease) {
            $tenant = $lease->tenant;

            if (! $tenant || ! $tenant->wantsNotification('lease_expiry_reminder')) {
                $this->line("Lease #{$lease->id}: tenant disabled lease expiry reminders — skipped");

                continue;
            }

            $daysLeft = (int) now()->startOfDay()->diffInDays($lease->contract_end_date, false);

            $recipients = collect();

            if ($lease->agent && $lease->agent->is_active) {
                $recipients->push($lease->agent);
            }

            // The agent's manager (reports_to) also gets the reminder so the
            // renewal / move-out conversation is not left to chance.
            $manager = $lease->agent?->manager;
            if ($manager && $manager->is_active && $manager->id !== $lease->agent?->id) {
                $recipients->push($manager);
            }

            // Fallback: tenant admins when the lease has no agent at all.
            if ($lease->agent_id === null) {
                $adminRoleIds = Role::whereIn('name', ['owner', 'admin'])->pluck('id');
                $recipients = $recipients->merge(
                    User::where('tenant_id', $tenant->id)
                        ->whereIn('role_id', $adminRoleIds)
                        ->where('is_active', true)
                        ->get(),
                );
            }

            $recipients = $recipients->unique('id');

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new LeaseExpiringReminder($lease, max(0, $daysLeft), $tenant));
                $notified++;
            }

            $lease->update(['reminder_sent_at' => now()]);

            $client = $lease->buyer?->full_name ?? $lease->lead?->full_name ?? 'Unknown';
            $this->line("Lease #{$lease->id} ({$client}) expires in {$daysLeft} day(s) on {$lease->contract_end_date->format('M j, Y')} — reminded ".$recipients->count().' recipient(s).');
        }

        // Flip any contracts whose date has already passed, so they stop being
        // treated as active on the dashboard.
        $expired = Lease::withoutGlobalScopes()
            ->whereIn('status', ['active', 'renewed'])
            ->where('contract_end_date', '<', now()->startOfDay())
            ->update(['status' => 'expired']);

        $this->info("Checked {$leases->count()} expiring lease(s), notified {$notified}, flagged {$expired} as expired.");

        return Command::SUCCESS;
    }
}
