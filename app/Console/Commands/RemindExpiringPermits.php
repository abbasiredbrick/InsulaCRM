<?php

namespace App\Console\Commands;

use App\Models\Property;
use App\Notifications\PermitExpiryReminder;
use App\Services\PermitExpiryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class RemindExpiringPermits extends Command
{
    protected $signature = 'permits:remind-expiring {--days= : Comma-separated days-before offsets (defaults to the tenant setting, 7,3,1)}';

    protected $description = 'Notify the assigned agent and their manager before a RERA/Madhmoun listing permit expires';

    public function handle(): int
    {
        $override = $this->parseDays($this->option('days'));

        $properties = Property::withoutGlobalScopes()
            ->with(['assignedAgent.manager', 'tenant'])
            ->whereNotNull('rera_permit_no')
            ->whereNotNull('permit_expires_at')
            ->get();

        $notified = 0;
        $checked = 0;

        foreach ($properties as $property) {
            $tenant = $property->tenant;

            if (! $tenant || ! $tenant->wantsNotification('permit_expiry_reminder')) {
                continue;
            }

            $daysLeft = PermitExpiryService::daysLeft($property);
            if ($daysLeft === null) {
                continue;
            }

            $checked++;

            $offsets = $override ?? PermitExpiryService::reminderDaysFor($tenant);

            // Fire on the exact offset day. Past expiry (or the expiry day
            // itself) collapses to a single "0" nudge so a permit whose window
            // was skipped — e.g. the date was set late — is never missed.
            $key = $daysLeft > 0 ? $daysLeft : 0;
            if ($daysLeft > 0 && ! in_array($daysLeft, $offsets, true)) {
                continue;
            }

            $sent = array_map('intval', (array) ($property->permit_reminders_sent ?? []));
            if (in_array($key, $sent, true)) {
                continue;
            }

            $recipients = PermitExpiryService::recipientsFor($property, $tenant);

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new PermitExpiryReminder($property, $daysLeft, $tenant));
                $notified++;
            }

            $sent[] = $key;
            $property->update(['permit_reminders_sent' => array_values(array_unique($sent))]);

            $this->line(sprintf(
                'Unit #%d (%s) permit expires %s — %s — reminded %d recipient(s).',
                $property->id,
                $property->display_name,
                $property->permit_expires_at?->format('M j, Y') ?? '—',
                $daysLeft < 0 ? abs($daysLeft).' day(s) ago' : "in {$daysLeft} day(s)",
                $recipients->count(),
            ));
        }

        $this->info("Checked {$checked} permit(s) in the reminder window, notified {$notified}.");

        return Command::SUCCESS;
    }

    /**
     * @return array<int, int>|null
     */
    protected function parseDays(?string $option): ?array
    {
        if ($option === null || trim($option) === '') {
            return null;
        }

        $days = collect(explode(',', $option))
            ->map(fn ($day) => (int) trim($day))
            ->filter(fn (int $day) => $day >= 1 && $day <= 365)
            ->unique()
            ->values()
            ->all();

        return $days ?: null;
    }
}
