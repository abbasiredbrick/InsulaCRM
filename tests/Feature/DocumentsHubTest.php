<?php

namespace Tests\Feature;

use App\Models\A2aContract;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\OfferLetter;
use Tests\TestCase;

class DocumentsHubTest extends TestCase
{
    private function reAdmin(): TestCase
    {
        return $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function reAgent(): \App\Models\User
    {
        return $this->actingAsRole('agent', ['business_mode' => 'realestate']);
    }

    private function createTemplate(string $name = 'LOI Main'): DocumentTemplate
    {
        return DocumentTemplate::create([
            'tenant_id' => $this->tenant->id,
            'name' => $name,
            'type' => 'loi',
            'content' => '<h1>{{deal.title}}</h1>',
        ]);
    }

    private function createContract(string $number, int $agentId): A2aContract
    {
        $lead = $this->createLead(['agent_id' => $agentId]);

        return A2aContract::create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $agentId,
            'contract_number' => $number,
            'counterparty_name' => 'Adja Traore',
            'counterparty_company' => 'Vierra',
            'share_pct' => 20,
            'funding_source' => 'from_agent',
            'scope_type' => 'lead',
            'lead_id' => $lead->id,
            'transaction_type' => 'lease',
            'status' => 'draft',
        ]);
    }

    private function createOffer(\App\Models\Deal $deal, string $no): OfferLetter
    {
        return OfferLetter::create([
            'tenant_id' => $this->tenant->id,
            'deal_id' => $deal->id,
            'lead_id' => $deal->lead_id,
            'offer_no' => $no,
            'status' => 'issued',
            'original_amount' => 500000,
            'approved_amount' => 500000,
        ]);
    }

    private function createGeneratedDocument(\App\Models\Deal $deal, DocumentTemplate $template, int $userId, string $name): GeneratedDocument
    {
        return GeneratedDocument::create([
            'tenant_id' => $this->tenant->id,
            'deal_id' => $deal->id,
            'template_id' => $template->id,
            'user_id' => $userId,
            'name' => $name,
            'content' => '<p>Merged</p>',
        ]);
    }

    public function test_admin_hub_defaults_to_agreements_tab(): void
    {
        $this->reAdmin();
        $this->createContract('A2A-HUB-1', $this->adminUser->id);

        $response = $this->get(route('documents.hub'));

        $response->assertOk()
            ->assertSee('Documents & Agreements')
            ->assertSee('Offer Letters')
            ->assertSee('Generated')
            ->assertSee('Templates')
            ->assertSee('A2A-HUB-1');
    }

    public function test_hub_is_not_available_in_wholesale_mode(): void
    {
        $this->actingAsAdmin();

        $this->get(route('documents.hub'))->assertNotFound();
    }

    public function test_agreements_tab_is_scoped_to_own_records_for_agent(): void
    {
        $this->reAdmin();
        $agent = $this->reAgent();

        $this->createContract('A2A-OWN-1', $agent->id);
        $this->createContract('A2A-ADMIN-1', $this->adminUser->id);

        $response = $this->get(route('documents.hub', ['tab' => 'agreements']));

        $response->assertOk()
            ->assertSee('A2A-OWN-1')
            ->assertDontSee('A2A-ADMIN-1');
    }

    public function test_agreements_tab_shows_all_for_admin(): void
    {
        $this->reAdmin();
        $agent = $this->createUserWithRole('agent');

        $this->createContract('A2A-ALL-1', $this->adminUser->id);
        $this->createContract('A2A-ALL-2', $agent->id);

        $this->get(route('documents.hub', ['tab' => 'agreements']))
            ->assertOk()
            ->assertSee('A2A-ALL-1')
            ->assertSee('A2A-ALL-2');
    }

    public function test_offer_letters_tab_is_scoped_to_own_deals_for_agent(): void
    {
        $this->reAdmin();
        $agent = $this->reAgent();

        $ownDeal = $this->createDeal(['agent_id' => $agent->id]);
        $adminDeal = $this->createDeal(['agent_id' => $this->adminUser->id]);

        $this->createOffer($ownDeal, 'OL-OWN-1');
        $this->createOffer($adminDeal, 'OL-ADMIN-1');

        $response = $this->get(route('documents.hub', ['tab' => 'offers']));

        $response->assertOk()
            ->assertSee('OL-OWN-1')
            ->assertDontSee('OL-ADMIN-1');
    }

    public function test_offer_letters_tab_shows_all_for_admin(): void
    {
        $this->reAdmin();
        $agent = $this->createUserWithRole('agent');

        $this->createOffer($this->createDeal(['agent_id' => $agent->id]), 'OL-ALL-1');
        $this->createOffer($this->createDeal(['agent_id' => $this->adminUser->id]), 'OL-ALL-2');

        $this->get(route('documents.hub', ['tab' => 'offers']))
            ->assertOk()
            ->assertSee('OL-ALL-1')
            ->assertSee('OL-ALL-2');
    }

    public function test_offer_letters_tab_links_to_deal_and_print(): void
    {
        $this->reAdmin();
        $deal = $this->createDeal();
        $offer = $this->createOffer($deal, 'OL-LINK-1');

        $response = $this->get(route('documents.hub', ['tab' => 'offers']));

        $response->assertOk()
            ->assertSee(route('deal.offers.print', $offer), false)
            ->assertSee(route('deals.show', $deal), false);
    }

    public function test_generated_tab_is_scoped_to_own_documents_for_agent(): void
    {
        $this->reAdmin();
        $agent = $this->reAgent();
        $template = $this->createTemplate();

        $this->createGeneratedDocument($this->createDeal(['agent_id' => $agent->id]), $template, $agent->id, 'Doc-OWN-1');
        $this->createGeneratedDocument($this->createDeal(['agent_id' => $this->adminUser->id]), $template, $this->adminUser->id, 'Doc-ADMIN-1');

        $this->get(route('documents.hub', ['tab' => 'generated']))
            ->assertOk()
            ->assertSee('Doc-OWN-1')
            ->assertDontSee('Doc-ADMIN-1');
    }

    public function test_generated_tab_shows_all_for_admin(): void
    {
        $this->reAdmin();
        $agent = $this->createUserWithRole('agent');
        $template = $this->createTemplate();

        $this->createGeneratedDocument($this->createDeal(), $template, $this->adminUser->id, 'Doc-ADMIN-2');
        $this->createGeneratedDocument($this->createDeal(['agent_id' => $agent->id]), $template, $agent->id, 'Doc-ALL-2');

        $this->get(route('documents.hub', ['tab' => 'generated']))
            ->assertOk()
            ->assertSee('Doc-ADMIN-2')
            ->assertSee('Doc-ALL-2');
    }

    public function test_templates_tab_is_admin_only(): void
    {
        $this->reAdmin();
        $this->reAgent();

        $this->get(route('documents.hub', ['tab' => 'templates']))->assertForbidden();
    }

    public function test_templates_tab_renders_for_admin(): void
    {
        $this->reAdmin();
        $template = $this->createTemplate('Template Hub Test');

        $this->get(route('documents.hub', ['tab' => 'templates']))
            ->assertOk()
            ->assertSee('Template Hub Test');
    }

    public function test_templates_tab_is_not_shown_as_nav_pill_for_agents(): void
    {
        $this->reAdmin();
        $this->reAgent();

        $this->get(route('documents.hub'))
            ->assertOk()
            ->assertDontSee('href="'.route('documents.hub', ['tab' => 'templates']).'"');
    }

    public function test_invalid_tab_falls_back_to_agreements(): void
    {
        $this->reAdmin();
        $contract = $this->createContract('A2A-FALLBACK-1', $this->adminUser->id);

        $this->get(route('documents.hub', ['tab' => 'nonsense']))
            ->assertOk()
            ->assertSee($contract->contract_number);
    }

    public function test_sidebar_replaces_a2a_menu_with_documents_and_agreements(): void
    {
        $this->reAdmin();

        $response = $this->get(route('dashboard'));

        $response->assertOk()
            ->assertSee('Documents & Agreements')
            ->assertDontSee('nav-link-title">A2A Contracts');
    }

    public function test_marketing_documents_item_is_hidden_in_realestate_mode(): void
    {
        $this->reAdmin();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('/document-templates');
    }

    public function test_a2a_index_still_renders_after_partial_extraction(): void
    {
        $this->reAdmin();
        $this->createContract('A2A-INDEX-1', $this->adminUser->id);

        $this->get(route('a2a.index'))
            ->assertOk()
            ->assertSee('A2A-INDEX-1');
    }

    public function test_document_templates_index_still_renders_after_partial_extraction(): void
    {
        $this->reAdmin();
        $this->createTemplate('Template Index Test');

        $this->get(route('document-templates.index'))
            ->assertOk()
            ->assertSee('Template Index Test');
    }
}
