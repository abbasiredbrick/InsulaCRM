<?php

namespace Tests\Feature;

use App\Models\Property;
use App\Notifications\PermitExpiryReminder;
use App\Services\PermitExpiryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PermitExpiryTest extends TestCase
{
    private function reAdmin(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function permitUnit(array $overrides = []): Property
    {
        return $this->createProperty(array_merge([
            'availability' => 'listed',
            'intent' => 'rent',
            'rera_permit_no' => 'PERMIT-'.fake()->unique()->numberBetween(1000, 9999),
            'sub_community' => 'Bloom Towers B',
            'community' => 'Bloom Towers',
            'city' => 'Dubai',
        ], $overrides));
    }

    public function test_permit_expiry_is_saved_and_resets_reminders_on_change(): void
    {
        $this->reAdmin();

        $unit = $this->permitUnit([
            'permit_expires_at' => now()->addDays(30),
            'permit_reminders_sent' => [7, 3],
        ]);

        $this->assertSame(30, $unit->permitDaysLeft());

        $unit->update(['permit_expires_at' => now()->addYear()]);

        $this->assertSame([], $unit->fresh()->permit_reminders_sent);
    }

    public function test_days_left_and_status_buckets(): void
    {
        $this->reAdmin();

        $expired = $this->permitUnit(['permit_expires_at' => now()->subDays(4)]);
        $due = $this->permitUnit(['permit_expires_at' => now()->addDays(5)]);
        $ok = $this->permitUnit(['permit_expires_at' => now()->addDays(40)]);

        $this->assertSame('expired', PermitExpiryService::status($expired, $this->tenant));
        $this->assertSame('due', PermitExpiryService::status($due, $this->tenant));
        $this->assertSame('ok', PermitExpiryService::status($ok, $this->tenant));
        $this->assertSame('none', PermitExpiryService::status($this->permitUnit(['permit_expires_at' => null]), $this->tenant));
    }

    public function test_renewals_tab_lists_permits_soonest_first(): void
    {
        $this->reAdmin();

        $later = $this->permitUnit(['permit_expires_at' => now()->addDays(60)]);
        $sooner = $this->permitUnit(['permit_expires_at' => now()->addDays(10)]);

        $response = $this->get(route('listings.index', ['tab' => 'renewals']));

        $response->assertOk();
        $renewals = $response->viewData('renewals');
        $this->assertSame($sooner->id, $renewals->first()->id);
        $this->assertSame($later->id, $renewals->last()->id);
    }

    public function test_permit_status_filter_limits_units(): void
    {
        $this->reAdmin();

        $expired = $this->permitUnit(['permit_expires_at' => now()->subDays(2)]);
        $valid = $this->permitUnit(['permit_expires_at' => now()->addDays(40)]);

        $response = $this->get(route('listings.index', ['permit_status' => 'expired']));

        $response->assertOk();
        $ids = $response->viewData('units')->pluck('id');
        $this->assertTrue($ids->contains($expired->id));
        $this->assertFalse($ids->contains($valid->id));
    }

    public function test_permits_export_returns_csv(): void
    {
        $this->reAdmin();

        $unit = $this->permitUnit([
            'permit_expires_at' => now()->addDays(15),
            'rera_permit_no' => 'RERA-EXPORT-1',
        ]);

        $response = $this->get(route('listings.export.permits'));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('RERA-EXPORT-1', $csv);
        $this->assertStringContainsString('permit-renewals.csv', $response->headers->get('content-disposition'));
    }

    public function test_permit_calendar_tab_renders(): void
    {
        $this->reAdmin();

        $this->permitUnit(['permit_expires_at' => now()->addDays(20)]);

        $this->get(route('listings.index', ['tab' => 'calendar']))
            ->assertOk()
            ->assertSee('pcal-body', false);
    }

    public function test_permit_calendar_events_json_lists_permits(): void
    {
        $this->reAdmin();

        $unit = $this->permitUnit(['permit_expires_at' => now()->addDays(20)]);

        $this->getJson(route('listings.permits.events'))
            ->assertOk()
            ->assertJsonFragment(['id' => 'permit-'.$unit->id]);
    }

    public function test_ical_feed_includes_permit_renewal(): void
    {
        $this->reAdmin();

        $unit = $this->permitUnit(['permit_expires_at' => now()->addDays(30)]);

        $this->adminUser->calendar_feed_token = 'permit-feed-token';
        $this->adminUser->save();

        $response = $this->get(route('calendar.feed', ['token' => 'permit-feed-token']));

        $response->assertOk();
        $response->assertSee('permit-'.$unit->id.'@keystone', false);
    }

    public function test_expiring_permit_notifies_agent_and_manager_once(): void
    {
        $this->reAdmin();

        $manager = $this->createUserWithRole('agent', [
            'tenant_id' => $this->tenant->id,
            'reports_to' => $this->adminUser->id,
        ]);
        $agent = $this->createUserWithRole('agent', [
            'tenant_id' => $this->tenant->id,
            'reports_to' => $manager->id,
        ]);

        $unit = $this->permitUnit([
            'assigned_agent_id' => $agent->id,
            'permit_expires_at' => now()->addDays(7),
        ]);

        $this->artisan('permits:remind-expiring')->assertSuccessful();

        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $agent->id)
            ->where('type', PermitExpiryReminder::class)
            ->count());
        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $manager->id)
            ->where('type', PermitExpiryReminder::class)
            ->count());
        $this->assertContains(7, (array) $unit->fresh()->permit_reminders_sent);

        $this->artisan('permits:remind-expiring')->assertSuccessful();
        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $agent->id)
            ->where('type', PermitExpiryReminder::class)
            ->count());
    }

    public function test_permit_reminder_respects_tenant_toggle(): void
    {
        $this->reAdmin();

        $agent = $this->createUserWithRole('agent', ['tenant_id' => $this->tenant->id]);

        $this->tenant->update([
            'notification_preferences' => array_merge(
                $this->tenant->notification_preferences ?? [],
                ['permit_expiry_reminder' => false],
            ),
        ]);

        $this->permitUnit([
            'assigned_agent_id' => $agent->id,
            'permit_expires_at' => now()->addDays(7),
        ]);

        $this->artisan('permits:remind-expiring')->assertSuccessful();

        $this->assertSame(0, DB::table('notifications')
            ->where('notifiable_id', $agent->id)
            ->where('type', PermitExpiryReminder::class)
            ->count());
    }
}
