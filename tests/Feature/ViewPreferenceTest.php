<?php

namespace Tests\Feature;

use App\Models\Deal;
use App\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ViewPreferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createTenantWithAdmin();
    }

    private function seedLead(): Lead
    {
        return Lead::factory()->create([
            'tenant_id' => $this->tenant->id,
            'agent_id' => $this->adminUser->id,
            'first_name' => 'PrefLead',
        ]);
    }

    private function seedDeal(): Deal
    {
        return Deal::factory()->create([
            'tenant_id' => $this->tenant->id,
            'lead_id' => $this->seedLead()->id,
            'agent_id' => $this->adminUser->id,
            'stage' => 'viewing',
            'title' => 'PrefDeal',
        ]);
    }

    public function test_leads_falls_back_to_the_list_without_recording_a_choice(): void
    {
        $this->seedLead();

        $response = $this->actingAs($this->adminUser)->get('/leads');

        $response->assertOk();
        $response->assertSee('data-live-results', false);
        $this->assertNull($this->adminUser->fresh()->preferredView('leads'));

        // The Table button must read as selected even though the URL is the
        // bare /leads dispatcher rather than /leads/table.
        $this->assertMatchesRegularExpression(
            '/class="btn btn-outline-primary btn-sm active"[^>]*>\s*<svg.*?<\/svg>\s*'.preg_quote(__('Table'), '/').'/s',
            $response->getContent()
        );
    }

    public function test_leads_kanban_choice_becomes_the_default_and_survives_revisiting_leads(): void
    {
        $this->seedLead();

        $this->actingAs($this->adminUser)->get('/leads/kanban')->assertOk();
        $this->assertSame('kanban', $this->adminUser->fresh()->preferredView('leads'));

        // /leads now resolves to the remembered board rather than the list.
        $this->actingAs($this->adminUser)
            ->get('/leads')
            ->assertRedirect(route('leads.kanban'));
    }

    public function test_switching_back_to_the_table_replaces_the_stored_choice(): void
    {
        $this->seedLead();

        $this->actingAs($this->adminUser)->get('/leads/kanban')->assertOk();
        $this->actingAs($this->adminUser)->get('/leads/table')->assertOk();

        $this->assertSame('table', $this->adminUser->fresh()->preferredView('leads'));

        $this->actingAs($this->adminUser)->get('/leads')->assertOk();
        $this->actingAs($this->adminUser)
            ->from('/leads')
            ->get('/leads')
            ->assertOk()
            ->assertSee('data-live-results', false);
    }

    public function test_leads_dispatcher_keeps_the_active_filters(): void
    {
        $this->seedLead();
        $this->actingAs($this->adminUser)->get('/leads/kanban')->assertOk();

        $this->actingAs($this->adminUser)
            ->get('/leads?search=PrefLead')
            ->assertRedirect(route('leads.kanban', ['search' => 'PrefLead']));
    }

    public function test_pipeline_falls_back_to_the_list_without_recording_a_choice(): void
    {
        $this->seedDeal();

        $response = $this->actingAs($this->adminUser)->get('/pipeline');

        $response->assertOk();
        $response->assertSee('PrefDeal');
        $this->assertNull($this->adminUser->fresh()->preferredView('pipeline'));
    }

    public function test_pipeline_board_choice_becomes_the_default_and_survives_revisiting_pipeline(): void
    {
        $this->seedDeal();

        $this->actingAs($this->adminUser)->get('/pipeline/board')->assertOk();
        $this->assertSame('board', $this->adminUser->fresh()->preferredView('pipeline'));

        $this->actingAs($this->adminUser)
            ->get('/pipeline')
            ->assertRedirect(route('pipeline.board'));
    }

    public function test_switching_the_pipeline_back_to_the_list_replaces_the_stored_choice(): void
    {
        $this->seedDeal();

        $this->actingAs($this->adminUser)->get('/pipeline/board')->assertOk();
        $this->actingAs($this->adminUser)->get('/deals')->assertOk();

        $this->assertSame('list', $this->adminUser->fresh()->preferredView('pipeline'));

        $this->actingAs($this->adminUser)->get('/pipeline')->assertOk();
        $this->actingAs($this->adminUser)
            ->get('/pipeline')
            ->assertOk()
            ->assertSee('PrefDeal');
    }

    public function test_pipeline_board_still_renders_with_the_dispatch_route_in_place(): void
    {
        $this->seedDeal();

        $this->actingAs($this->adminUser)
            ->get('/pipeline/board')
            ->assertOk()
            ->assertSee('pipeline-board', false);
    }

    public function test_the_menu_entry_is_labelled_pipeline(): void
    {
        $this->actingAs($this->adminUser);

        $response = $this->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('pipeline'), false);
        $response->assertDontSee('Transactions');
    }
}
