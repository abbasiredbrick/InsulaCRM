<?php

namespace Tests\Feature;

use App\Models\ServiceProvider;
use App\Models\ServiceProviderRegistrationLink;
use App\Notifications\ServiceProviderActivity;
use App\Notifications\ServiceProviderApproved;
use App\Notifications\ServiceProviderChangesRequested;
use App\Notifications\ServiceProviderInvite;
use App\Notifications\ServiceProviderRejected;
use App\Notifications\ServiceProviderSubmissionReceived;
use App\Services\ServiceProviderService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ServiceProviderWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    private function admin(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
    }

    private function makeLink(array $data = []): ServiceProviderRegistrationLink
    {
        return app(ServiceProviderService::class)->createRegistrationLink(
            $this->tenant,
            $this->adminUser,
            array_merge([
                'provider_email' => 'provider@example.com',
                'provider_name' => 'Bright Cleaning LLC',
                'internal_note' => null,
            ], $data),
        );
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => 'cleaning',
            'company_name' => 'Bright Cleaning LLC',
            'trade_license_number' => 'TL-1045-2024',
            'services_offered' => 'Apartment cleaning and pest control',
            'representative_name' => 'Rashid Khan',
            'representative_emirates_id' => '784-1998-1234567-1',
            'email' => 'hello@brightcleaning.ae',
            'mobile' => '+971501112233',
            'city' => 'Dubai',
            'website' => 'https://brightcleaning.ae',
        ], $overrides);
    }

    private function files(): array
    {
        return [
            'trade_license_document' => UploadedFile::fake()->create('license.pdf', 200, 'application/pdf'),
            'emirates_id_document' => UploadedFile::fake()->image('emirates-id.png'),
        ];
    }

    public function test_a_provider_can_register_via_a_link_and_everyone_is_notified(): void
    {
        Notification::fake();
        $this->admin();

        $link = $this->makeLink();

        $response = $this->post(route('service-providers.public.store', $link->token), $this->payload() + $this->files());

        $response->assertRedirect(route('service-providers.public.status', ServiceProvider::first()->edit_token));

        $this->assertDatabaseHas('service_providers', [
            'tenant_id' => $this->tenant->id,
            'company_name' => 'Bright Cleaning LLC',
            'status' => 'pending',
            'registration_link_id' => $link->id,
        ]);

        $provider = ServiceProvider::first();
        $this->assertNotNull($provider->edit_token);
        $this->assertDatabaseHas('service_provider_documents', [
            'service_provider_id' => $provider->id,
            'doc_type' => 'trade_license',
        ]);
        $this->assertDatabaseHas('service_provider_documents', [
            'service_provider_id' => $provider->id,
            'doc_type' => 'emirates_id',
        ]);
        $this->assertDatabaseHas('service_provider_reviews', [
            'service_provider_id' => $provider->id,
            'action' => 'submitted',
        ]);

        $this->assertNotNull($link->fresh()->used_at, 'the link must be consumed on submission');

        Notification::assertSentTo($this->adminUser, ServiceProviderActivity::class);
        Notification::assertSentOnDemand(ServiceProviderSubmissionReceived::class);
    }

    public function test_expired_used_and_revoked_links_show_the_invalid_page_and_cannot_register(): void
    {
        $this->admin();

        foreach (['expired', 'used', 'revoked'] as $state) {
            $link = $this->makeLink(['provider_email' => 'p'.$state.'@example.com']);
            match ($state) {
                'expired' => $link->update(['expires_at' => now()->subMinute()]),
                'used' => $link->update(['used_at' => now()]),
                'revoked' => $link->update(['revoked_at' => now()]),
            };

            $this->get(route('service-providers.public.create', $link->token))
                ->assertStatus(200)
                ->assertSee('Registration link unavailable');

            $this->post(route('service-providers.public.store', $link->token), $this->payload() + $this->files())
                ->assertSee('Registration link unavailable');

            $this->assertDatabaseCount('service_providers', 0);
        }
    }

    public function test_registration_validates_the_form(): void
    {
        $this->admin();

        $link = $this->makeLink();
        $files = $this->files();
        $files['trade_license_document'] = null;

        $this->post(route('service-providers.public.store', $link->token), $this->payload([
            'representative_emirates_id' => 'not-an-id',
        ]) + $files)
            ->assertSessionHasErrors(['representative_emirates_id', 'trade_license_document']);

        $this->assertDatabaseCount('service_providers', 0);
    }

    public function test_an_agent_can_create_and_email_a_registration_link(): void
    {
        Notification::fake();
        $this->admin();

        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);

        $this->post(route('service-providers.links.store'), [
            'provider_email' => 'owner@acme.ae',
            'provider_name' => 'Acme Maintenance',
            'internal_note' => 'From agent referral',
        ])->assertRedirect(route('service-providers.links'));

        $link = ServiceProviderRegistrationLink::first();
        $this->assertSame('Acme Maintenance', $link->provider_name);
        $this->assertSame($this->tenant->id, $link->tenant_id);

        $this->post(route('service-providers.links.send', $link), ['provider_email' => 'owner@acme.ae'])
            ->assertSessionHas('success');

        Notification::assertSentOnDemand(ServiceProviderInvite::class);
    }

    public function test_only_admins_can_review(): void
    {
        $this->admin();
        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);

        $this->get(route('service-providers.review'))->assertForbidden();

        $this->actingAs($this->adminUser);
        $this->get(route('service-providers.review'))->assertStatus(200);
    }

    public function test_approval_publishes_the_provider_to_the_directory(): void
    {
        Notification::fake();
        $this->admin();

        $provider = $this->registerProvider($this->makeLink());

        $this->post(route('service-providers.review.store', $provider), ['action' => 'approved'])
            ->assertRedirect(route('service-providers.review'));

        $this->assertSame('approved', $provider->fresh()->status);
        Notification::assertSentOnDemand(ServiceProviderApproved::class);
        Notification::assertSentTo($this->adminUser, ServiceProviderActivity::class);

        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);

        $this->get(route('service-providers.index'))
            ->assertStatus(200)
            ->assertSee('Bright Cleaning LLC');
    }

    public function test_request_changes_loop_ends_back_in_review(): void
    {
        Notification::fake();
        $this->admin();

        $provider = $this->registerProvider($this->makeLink());

        $this->post(route('service-providers.review.store', $provider), [
            'action' => 'changes_requested',
            'comment' => 'Please attach a renewed trade license.',
        ])->assertRedirect(route('service-providers.review'));

        $this->assertSame('changes_requested', $provider->fresh()->status);
        Notification::assertSentOnDemand(ServiceProviderChangesRequested::class);
        Notification::assertSentTo($this->adminUser, ServiceProviderActivity::class);

        $this->get(route('service-providers.public.status', $provider->edit_token))
            ->assertSee('Please attach a renewed trade license.');

        $this->get(route('service-providers.public.edit', $provider->edit_token))
            ->assertStatus(200)
            ->assertSee('Please attach a renewed trade license.');

        $newFiles = $this->files();
        $newFiles['trade_license_document'] = UploadedFile::fake()->create('license-renewed.pdf', 300, 'application/pdf');

        $this->post(route('service-providers.public.update', $provider->edit_token),
            $this->payload(['trade_license_number' => 'TL-1045-2025']) + $newFiles)
            ->assertRedirect(route('service-providers.public.status', $provider->edit_token));

        $this->assertSame('pending', $provider->fresh()->status);
        $this->assertDatabaseHas('service_provider_reviews', [
            'service_provider_id' => $provider->id,
            'action' => 'resubmitted',
        ]);
        Notification::assertSentTo($this->adminUser, ServiceProviderActivity::class);
    }

    public function test_rejection_is_final_and_hidden_from_agents(): void
    {
        Notification::fake();
        $this->admin();

        $provider = $this->registerProvider($this->makeLink());

        $this->post(route('service-providers.review.store', $provider), [
            'action' => 'rejected',
            'comment' => 'Trade license could not be verified.',
        ])->assertRedirect(route('service-providers.review'));

        $this->assertSame('rejected', $provider->fresh()->status);
        Notification::assertSentOnDemand(ServiceProviderRejected::class);

        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);

        $this->get(route('service-providers.index'))->assertDontSee('Bright Cleaning LLC');
        $this->get(route('service-providers.show', $provider))->assertForbidden();

        $this->actingAs($this->adminUser);
        $this->get(route('service-providers.show', $provider))->assertStatus(200);
    }

    public function test_the_directory_only_lists_approved_providers(): void
    {
        $this->admin();

        $approved = $this->registerProvider($this->makeLink());
        $approved->update(['status' => 'approved']);

        $pending = $this->registerProvider($this->makeLink(['provider_name' => 'Pending Co']));

        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);

        $response = $this->get(route('service-providers.index'));
        $response->assertSee('Bright Cleaning LLC');
        $response->assertDontSee('Pending Co');
    }

    public function test_providers_are_isolated_between_tenants(): void
    {
        $this->actingAsAdmin(['business_mode' => 'realestate']);
        $tenantA = $this->tenant;

        $this->registerProvider($this->makeLink());

        $this->createTenantWithAdmin([
            'name' => 'Other Company',
            'slug' => 'other-company',
            'email' => 'other@test.com',
            'business_mode' => 'realestate',
        ]);
        $tenantB = $this->tenant;
        $adminB = $this->adminUser;

        $linkB = app(ServiceProviderService::class)->createRegistrationLink($tenantB, $adminB, [
            'provider_email' => 'b@example.com',
            'provider_name' => 'Tenant B Co',
            'internal_note' => null,
        ]);

        $this->post(route('service-providers.public.store', $linkB->token),
            $this->payload(['company_name' => 'Tenant B Co']) + $this->files())
            ->assertRedirect();

        $providerB = ServiceProvider::withoutGlobalScopes()->where('company_name', 'Tenant B Co')->first();
        $this->assertSame($tenantB->id, $providerB->tenant_id);

        $this->actingAs($adminB);
        $this->post(route('service-providers.review.store', $providerB), ['action' => 'approved'])
            ->assertRedirect();

        $agentB = $this->createUserWithRole('agent');
        $this->actingAs($agentB);

        $this->get(route('service-providers.index'))
            ->assertSee('Tenant B Co')
            ->assertDontSee('Bright Cleaning LLC');

        $this->assertNotSame($tenantA->id, $tenantB->id);
        $this->assertDatabaseHas('service_providers', [
            'tenant_id' => $tenantB->id,
            'company_name' => 'Tenant B Co',
        ]);
    }

    public function test_documents_are_downloadable_within_role_visibility(): void
    {
        $this->admin();

        $pending = $this->registerProvider($this->makeLink());
        $approved = $this->registerProvider($this->makeLink(['provider_name' => 'Approved Co']));
        $approved->update(['status' => 'approved']);

        $document = $approved->documents->first();

        $agent = $this->createUserWithRole('agent');
        $this->actingAs($agent);

        $this->get(route('service-providers.documents.download', $document))->assertStatus(200);

        $this->actingAs($this->adminUser);
        $this->get(route('service-providers.documents.download', $pending->documents->first()))
            ->assertStatus(200);
    }

    private function registerProvider(ServiceProviderRegistrationLink $link): ServiceProvider
    {
        $this->post(route('service-providers.public.store', $link->token), $this->payload() + $this->files())
            ->assertRedirect();

        return ServiceProvider::first();
    }
}
