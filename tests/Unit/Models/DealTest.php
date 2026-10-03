<?php

namespace Tests\Unit\Models;

use App\Models\Deal;
use Tests\TestCase;

class DealTest extends TestCase
{
    public function test_stages_constant_has_all_stages(): void
    {
        $expected = [
            'prospecting', 'contacting', 'engaging', 'offer_presented',
            'under_contract', 'dispositions', 'assigned', 'closing',
            'closed_won', 'closed_lost',
        ];

        $this->assertEquals($expected, array_keys(Deal::STAGES));
    }

    public function test_stage_labels_returns_translated_labels(): void
    {
        $labels = Deal::stageLabels();

        $this->assertIsArray($labels);
        $this->assertArrayHasKey('prospecting', $labels);
        $this->assertEquals('Prospecting', $labels['prospecting']);
        $this->assertEquals('Closed Won', $labels['closed_won']);
    }

    public function test_stage_label_returns_single_translated_label(): void
    {
        $this->assertEquals('Under Contract', Deal::stageLabel('under_contract'));
        $this->assertEquals('Closed Lost', Deal::stageLabel('closed_lost'));
    }

    public function test_stage_label_handles_unknown_stage(): void
    {
        $label = Deal::stageLabel('some_unknown_stage');
        $this->assertEquals('Some Unknown Stage', $label);
    }

    public function test_due_diligence_days_remaining(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal(['due_diligence_end_date' => now()->addDays(5)]);

        $this->assertEquals(5, $deal->due_diligence_days_remaining);
    }

    public function test_due_diligence_days_remaining_null_when_no_date(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal(['due_diligence_end_date' => null]);

        $this->assertNull($deal->due_diligence_days_remaining);
    }

    public function test_is_due_diligence_urgent_when_two_days(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal(['due_diligence_end_date' => now()->addDays(2)]);

        $this->assertTrue($deal->is_due_diligence_urgent);
    }

    public function test_is_due_diligence_not_urgent_when_far_away(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal(['due_diligence_end_date' => now()->addDays(10)]);

        $this->assertFalse($deal->is_due_diligence_urgent);
    }

    public function test_stage_changed_at_auto_set_on_create(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal(['stage_changed_at' => null]);

        $this->assertNotNull($deal->fresh()->stage_changed_at);
    }

    public function test_rent_offer_sent_sets_anchor_and_validity_deadline(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_sent',
            'offer_validity_days' => 5,
        ]);

        $fresh = $deal->fresh();
        $this->assertEquals(now()->startOfDay()->toDateString(), $fresh->offer_sent_date->toDateString());
        $this->assertTrue($fresh->due_diligence_applies);
        $this->assertEquals(
            now()->startOfDay()->addDays(5)->toDateString(),
            $fresh->due_diligence_end_date->toDateString()
        );
    }

    public function test_rent_offer_validity_defaults_to_seven_days(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_sent',
        ]);

        $this->assertEquals(
            now()->startOfDay()->addDays(7)->toDateString(),
            $deal->fresh()->due_diligence_end_date->toDateString()
        );
    }

    public function test_rent_offer_signed_sets_anchor_and_registration_deadline(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_signed',
            'registration_deadline_days' => 4,
        ]);

        $fresh = $deal->fresh();
        $this->assertEquals(now()->startOfDay()->toDateString(), $fresh->offer_signed_date->toDateString());
        $this->assertTrue($fresh->due_diligence_applies);
        $this->assertEquals(
            now()->startOfDay()->addDays(4)->toDateString(),
            $fresh->due_diligence_end_date->toDateString()
        );
    }

    public function test_rent_deadline_persists_through_registration_stages(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'offer_signed',
            'registration_deadline_days' => 5,
        ]);
        $signedDate = $deal->fresh()->offer_signed_date;

        $deal->update(['stage' => 'deposit_collected']);

        $fresh = $deal->fresh();
        $this->assertEquals($signedDate->toDateString(), $fresh->offer_signed_date->toDateString());
        $this->assertTrue($fresh->due_diligence_applies);
        $this->assertEquals($signedDate->copy()->addDays(5)->toDateString(), $fresh->due_diligence_end_date->toDateString());
    }

    public function test_rent_deadline_does_not_apply_after_registration(): void
    {
        $this->actingAsAdmin();
        $deal = $this->createDeal([
            'deal_type' => 'rent',
            'stage' => 'moved_in',
            'offer_signed_date' => now()->subDays(10)->startOfDay(),
        ]);

        $fresh = $deal->fresh();
        $this->assertFalse($fresh->due_diligence_applies);
        $this->assertNull($fresh->due_diligence_end_date);
    }

    public function test_sale_under_contract_deadline_uses_contract_date_plus_inspection(): void
    {
        $this->actingAsAdmin();
        $contractDate = now()->subDays(3)->startOfDay();
        $deal = $this->createDeal([
            'deal_type' => 'sale',
            'stage' => 'under_contract',
            'contract_date' => $contractDate,
            'inspection_period_days' => 10,
        ]);

        $this->assertEquals(
            $contractDate->copy()->addDays(10)->toDateString(),
            $deal->fresh()->due_diligence_end_date->toDateString()
        );
    }

    public function test_period_label_is_offer_validity_for_rent_offer_sent(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_sent']);

        $this->assertEquals('Offer validity', $deal->dueDiligencePeriodLabel($this->tenant));
    }

    public function test_period_label_is_payment_tawtheeq_for_rent_offer_signed(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $deal = $this->createDeal(['deal_type' => 'rent', 'stage' => 'offer_signed']);

        $this->assertEquals('Payment & Tawtheeq/Ejari deadline', $deal->dueDiligencePeriodLabel($this->tenant));
    }
}
