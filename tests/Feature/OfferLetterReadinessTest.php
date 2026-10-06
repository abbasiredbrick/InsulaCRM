<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use App\Services\OfferLetterReadiness;
use App\Services\OfferLetterService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The gate in front of creating an offer letter.
 *
 * An offer letter is a legal document carrying the company's letterhead, its
 * authority to bind (signature + seal), an instruction for the client's money
 * (IBAN), and the client's own details to sign against. Producing one with any
 * of those missing gives away something the company cannot take back, so
 * creation is refused until each is present.
 */
class OfferLetterReadinessTest extends TestCase
{
    private function readyDeal(array $dealOverrides = []): Deal
    {
        // Called more than once per test in places; creating a second tenant
        // would collide on its unique slug.
        if (! isset($this->tenant)) {
            $this->actingAsAdmin(['business_mode' => 'realestate']);
        }

        $lead = Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'first_name' => 'Amina',
            'last_name' => 'Haddad',
            'email' => 'amina@example.test',
            'phone' => '+971 50 111 2233',
            'status' => 'active_client',
        ]);

        return Deal::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $lead->id,
            'deal_type' => 'sale',
            'stage' => 'active_listing',
            'contract_price' => 500000,
        ], $dealOverrides));
    }

    /**
     * @return array<int, string>
     */
    private function blockerKeys(Deal $deal): array
    {
        return array_column(app(OfferLetterReadiness::class)->blockers($deal), 'key');
    }

    private function warningKeys(Deal $deal): array
    {
        return array_column(app(OfferLetterReadiness::class)->warnings($deal), 'key');
    }

    public function test_the_base_fixture_is_a_fully_configured_company(): void
    {
        $deal = $this->readyDeal();

        $this->assertSame([], $this->blockerKeys($deal), 'the shared tenant fixture must not trip the gate');
        $this->assertTrue(app(OfferLetterReadiness::class)->isReady($deal));
        $this->assertNull(app(OfferLetterReadiness::class)->gateError($deal));
    }

    public function test_every_blocker_actually_blocks(): void
    {
        $service = app(OfferLetterReadiness::class);
        $deal = $this->readyDeal();

        // Strip the company and the client one field at a time and prove each
        // key bites, so a new spec cannot be added and quietly not enforced.
        $fields = [
            'company.name' => ['address', 'phone', 'signature_path', 'stamp_path', 'iban_letter_path'],
            'company.address' => ['name', 'phone', 'signature_path', 'stamp_path', 'iban_letter_path'],
            'company.phone' => ['name', 'address', 'signature_path', 'stamp_path', 'iban_letter_path'],
            'company.email' => ['name', 'address', 'phone', 'signature_path', 'stamp_path', 'iban_letter_path'],
            'company.signature' => ['name', 'address', 'phone', 'stamp_path', 'iban_letter_path'],
            'company.stamp' => ['name', 'address', 'phone', 'signature_path', 'iban_letter_path'],
            'company.iban' => ['name', 'address', 'phone', 'signature_path', 'stamp_path'],
        ];

        $all = array_merge(
            ['name', 'address', 'phone', 'email', 'signature_path', 'stamp_path', 'iban_letter_path'],
            ...array_values($fields),
        );

        foreach ($fields as $key => $keep) {
            $stripped = array_diff($all, $keep);

            $tenant = $this->tenant;
            foreach ($stripped as $field) {
                // '' rather than null: these columns are NOT NULL, and
                // filled('') is false all the same.
                $tenant->{$field} = '';
            }
            $tenant->save();
            $deal->refresh();

            $this->assertContains($key, $this->blockerKeys($deal), "clearing the fields behind {$key} must block");
            $this->assertFalse($service->isReady($deal));
        }

        // Restore for any later assertion in this test.
        $this->readyDeal();
    }

    public function test_typed_bank_details_satisfy_the_iban_requirement(): void
    {
        $deal = $this->readyDeal();

        $this->tenant->update(['iban_letter_path' => null]);
        $deal->refresh();

        $this->assertContains('company.iban', $this->blockerKeys($deal));

        $this->tenant->update(['bank_details' => 'IBAN AE070331234567890123456']);
        $deal->refresh();

        $this->assertNotContains('company.iban', $this->blockerKeys($deal));
    }

    public function test_a_malformed_client_email_blocks_because_the_signing_link_is_sent_by_email(): void
    {
        $deal = $this->readyDeal();
        $deal->lead->update(['email' => 'not-an-address']);
        $deal->refresh();

        $this->assertContains('client.email', $this->blockerKeys($deal));
    }

    public function test_a_missing_client_name_or_phone_blocks(): void
    {
        $deal = $this->readyDeal();

        $deal->lead->update(['first_name' => '', 'last_name' => '']);
        $deal->refresh();
        $this->assertContains('client.name', $this->blockerKeys($deal));

        $deal = $this->readyDeal();
        $deal->lead->update(['phone' => null]);
        $deal->refresh();
        $this->assertContains('client.phone', $this->blockerKeys($deal));
    }

    public function test_a_deal_with_no_type_blocks(): void
    {
        $deal = $this->readyDeal(['deal_type' => null]);

        $this->assertContains('deal.type', $this->blockerKeys($deal));
    }

    public function test_an_off_market_deal_is_only_warned_about_not_blocked(): void
    {
        // No property linked at all: a letter for a property that is not in
        // inventory is legitimate, and the contract value is typed into the
        // create form.
        $deal = $this->readyDeal(['property_id' => null]);

        $this->assertSame([], $this->blockerKeys($deal));
        $this->assertContains('deal.unit_chosen', $this->warningKeys($deal));
    }

    public function test_a_logo_is_only_ever_a_warning(): void
    {
        $deal = $this->readyDeal();

        $this->tenant->update(['logo_path' => null, 'letterhead_display' => 'name']);
        $deal->refresh();

        $this->assertSame([], $this->blockerKeys($deal));
        // Name-only is a deliberate choice, so not even a warning.
        $this->assertNotContains('company.logo', $this->warningKeys($deal));

        $this->tenant->update(['letterhead_display' => 'both']);
        $deal->refresh();

        $this->assertContains('company.logo', $this->warningKeys($deal));
    }

    public function test_a_configured_but_unreadable_asset_is_a_warning(): void
    {
        Storage::fake('public');

        $deal = $this->readyDeal();

        // Path set, file absent — the quiet failure that produces an
        // unsigned-looking letter.
        $this->assertContains('asset.signature_path', $this->warningKeys($deal));
        $this->assertSame([], $this->blockerKeys($deal), 'a broken file must not block, the letter still prints');

        Storage::disk('public')->put('letter-assets/signature.png', 'x');
        Storage::disk('public')->put('letter-assets/stamp.png', 'x');
        Storage::disk('public')->put('letter-assets/iban.png', 'x');
        $deal->refresh();

        $this->assertNotContains('asset.signature_path', $this->warningKeys($deal));
        $this->assertNotContains('asset.stamp_path', $this->warningKeys($deal));
        $this->assertNotContains('asset.iban_letter_path', $this->warningKeys($deal));
    }

    public function test_the_gate_message_names_every_missing_item(): void
    {
        $this->readyDeal();

        $this->tenant->update(['phone' => null, 'stamp_path' => null]);
        $deal = Deal::where('tenant_id', $this->tenant->id)->latest('id')->first();
        $deal->lead->update(['phone' => null]);
        $deal->refresh();

        $message = app(OfferLetterReadiness::class)->gateError($deal);

        $this->assertNotNull($message);
        // Names all three, not just the first — an agent fixing one field per
        // round-trip is a bad afternoon.
        $this->assertStringContainsString('Company phone', $message);
        $this->assertStringContainsString('E-stamp', $message);
        $this->assertStringContainsString('Client phone', $message);
    }

    public function test_creating_an_offer_is_refused_while_the_gate_is_closed(): void
    {
        $deal = $this->readyDeal();
        $this->tenant->update(['signature_path' => null]);
        $deal->refresh();

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000])
            ->assertSessionHasErrors();

        $this->assertSame(0, $deal->offerLetters()->count());

        // Once configured, the same post goes through.
        $this->tenant->update(['signature_path' => 'letter-assets/signature.png']);
        $deal->refresh();

        $this->post(route('deal.offers.store', $deal), ['original_amount' => 500000])
            ->assertRedirect(route('deals.show', $deal));

        $this->assertSame(1, $deal->offerLetters()->count());
    }

    public function test_the_service_refuses_to_create_behind_the_controllers_back(): void
    {
        $deal = $this->readyDeal();
        $this->tenant->update(['iban_letter_path' => null, 'bank_details' => null]);
        $deal->refresh();

        // The controller is not the only caller: a command or a second
        // controller must not be able to write an offer letter the gate would
        // have refused.
        $this->expectException(\RuntimeException::class);

        app(OfferLetterService::class)->createFromValidated($deal, $this->adminUser, [
            'original_amount' => 500000,
        ]);
    }

    public function test_the_deal_page_explains_the_blockers_and_disables_creating(): void
    {
        // The readiness panel lives on the rent deal page; sale deals get the
        // wholesale offers panel, which does not create letters.
        $deal = $this->readyDeal(['deal_type' => 'rent', 'stage' => 'moved_in']);
        $this->tenant->update(['stamp_path' => null]);
        $deal->refresh();

        $this->get(route('deals.show', $deal))
            ->assertOk()
            ->assertSee('E-stamp / company seal')
            ->assertSee('disabled', false);
    }
}
