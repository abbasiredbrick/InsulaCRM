<?php

namespace Tests\Feature;

use App\Notifications\DueDiligenceWarning;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DueDiligenceWarningTest extends TestCase
{
    public function test_rent_offer_signed_deal_within_window_notifies_agent_and_admin(): void
    {
        Notification::fake([DueDiligenceWarning::class]);
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_signed',
            'offer_signed_date' => now()->startOfDay(),
            'registration_deadline_days' => 2,
        ]);

        $this->artisan('deals:check-due-diligence')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, DueDiligenceWarning::class);
    }

    public function test_rent_offer_sent_deal_within_window_notifies(): void
    {
        Notification::fake([DueDiligenceWarning::class]);
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_sent',
            'offer_sent_date' => now()->startOfDay(),
            'offer_validity_days' => 3,
        ]);

        $this->artisan('deals:check-due-diligence')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, DueDiligenceWarning::class);
    }

    public function test_sale_under_contract_deal_still_within_window_notifies(): void
    {
        Notification::fake([DueDiligenceWarning::class]);
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'under_contract',
            'contract_date' => now()->subDays(5)->startOfDay(),
            'inspection_period_days' => 7,
        ]);

        $this->artisan('deals:check-due-diligence')->assertSuccessful();

        Notification::assertSentTo($this->adminUser, DueDiligenceWarning::class);
    }

    public function test_rent_deal_past_deadline_is_not_notified(): void
    {
        Notification::fake([DueDiligenceWarning::class]);
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_signed',
            'offer_signed_date' => now()->subDays(10)->startOfDay(),
            'registration_deadline_days' => 5,
        ]);

        $this->artisan('deals:check-due-diligence')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_rent_deal_at_moved_in_is_not_notified(): void
    {
        Notification::fake([DueDiligenceWarning::class]);
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'moved_in',
            'offer_signed_date' => now()->startOfDay(),
        ]);

        $this->artisan('deals:check-due-diligence')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_sale_deal_moved_past_under_contract_is_not_notified(): void
    {
        Notification::fake([DueDiligenceWarning::class]);
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'inspection',
            'contract_date' => now()->subDays(5)->startOfDay(),
            'inspection_period_days' => 7,
            'due_diligence_end_date' => now()->addDays(2),
        ]);

        $this->artisan('deals:check-due-diligence')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_rent_stage_change_to_offer_sent_auto_sets_deadline(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);

        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'viewing_done',
            'offer_validity_days' => 7,
        ]);

        $this->patch("/pipeline/{$deal->id}/stage", ['stage' => 'offer_sent'])
            ->assertJson(['success' => true]);

        $fresh = $deal->fresh();
        $this->assertNotNull($fresh->offer_sent_date);
        $this->assertTrue($fresh->due_diligence_applies);
        $this->assertEquals(
            now()->startOfDay()->addDays(7)->toDateString(),
            $fresh->due_diligence_end_date->toDateString()
        );
    }
}
