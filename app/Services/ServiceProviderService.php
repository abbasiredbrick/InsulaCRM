<?php

namespace App\Services;

use App\Models\ServiceProvider;
use App\Models\ServiceProviderDocument;
use App\Models\ServiceProviderRegistrationLink;
use App\Models\ServiceProviderReview;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ServiceProviderActivity;
use App\Notifications\ServiceProviderApproved;
use App\Notifications\ServiceProviderChangesRequested;
use App\Notifications\ServiceProviderInvite;
use App\Notifications\ServiceProviderRejected;
use App\Notifications\ServiceProviderSubmissionReceived;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ServiceProviderService
{
    public function staffRecipients(Tenant $tenant): Collection
    {
        return User::where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereHas('role', fn ($q) => $q->whereIn('name', ['owner', 'admin']))
            ->get();
    }

    public function createRegistrationLink(Tenant $tenant, User $creator, array $data): ServiceProviderRegistrationLink
    {
        return ServiceProviderRegistrationLink::create([
            'tenant_id' => $tenant->id,
            'token' => Str::uuid(),
            'provider_email' => $data['provider_email'] ?? null,
            'provider_name' => $data['provider_name'] ?? null,
            'internal_note' => $data['internal_note'] ?? null,
            'created_by' => $creator->id,
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function invite(ServiceProviderRegistrationLink $link, string $email): void
    {
        $this->configureMail($link->tenant);

        Notification::route('mail', $email)->notify(new ServiceProviderInvite($link));
    }

    public function register(ServiceProviderRegistrationLink $link, array $data, array $files): ServiceProvider
    {
        $provider = ServiceProvider::create([
            'tenant_id' => $link->tenant_id,
            'status' => 'pending',
            'category' => $data['category'],
            'company_name' => $data['company_name'],
            'trade_license_number' => $data['trade_license_number'],
            'services_offered' => $data['services_offered'] ?? null,
            'representative_name' => $data['representative_name'],
            'representative_emirates_id' => $data['representative_emirates_id'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'city' => $data['city'] ?? null,
            'website' => $data['website'] ?? null,
            'edit_token' => Str::uuid(),
            'registration_link_id' => $link->id,
        ]);

        $this->storeDocuments($provider, $files);

        ServiceProviderReview::create([
            'service_provider_id' => $provider->id,
            'reviewer_id' => null,
            'action' => 'submitted',
        ]);

        $link->update(['used_at' => now()]);

        $this->configureMail($link->tenant);
        Notification::send($this->staffRecipients($link->tenant), new ServiceProviderActivity($provider, 'submitted'));
        Notification::route('mail', $provider->email)
            ->notify(new ServiceProviderSubmissionReceived($provider));

        return $provider;
    }

    public function requestChanges(ServiceProvider $provider, User $reviewer, string $comment): void
    {
        $provider->update(['status' => 'changes_requested']);

        ServiceProviderReview::create([
            'service_provider_id' => $provider->id,
            'reviewer_id' => $reviewer->id,
            'action' => 'changes_requested',
            'comment' => $comment,
        ]);

        $this->configureMail($provider->tenant);
        Notification::route('mail', $provider->email)
            ->notify(new ServiceProviderChangesRequested($provider, $comment));
        Notification::send($this->staffRecipients($provider->tenant), new ServiceProviderActivity($provider, 'changes_requested'));
    }

    public function resubmit(ServiceProvider $provider, array $data, array $files): void
    {
        $provider->update([
            'status' => 'pending',
            'category' => $data['category'],
            'company_name' => $data['company_name'],
            'trade_license_number' => $data['trade_license_number'],
            'services_offered' => $data['services_offered'] ?? null,
            'representative_name' => $data['representative_name'],
            'representative_emirates_id' => $data['representative_emirates_id'],
            'email' => $data['email'],
            'mobile' => $data['mobile'],
            'city' => $data['city'] ?? null,
            'website' => $data['website'] ?? null,
        ]);

        $this->replaceDocuments($provider, $files);

        ServiceProviderReview::create([
            'service_provider_id' => $provider->id,
            'reviewer_id' => null,
            'action' => 'resubmitted',
        ]);

        $this->configureMail($provider->tenant);
        Notification::send($this->staffRecipients($provider->tenant), new ServiceProviderActivity($provider, 'resubmitted'));
        Notification::route('mail', $provider->email)
            ->notify(new ServiceProviderSubmissionReceived($provider));
    }

    public function approve(ServiceProvider $provider, User $reviewer, ?string $comment = null): void
    {
        $provider->update([
            'status' => 'approved',
            'approved_by' => $reviewer->id,
            'approved_at' => now(),
        ]);

        ServiceProviderReview::create([
            'service_provider_id' => $provider->id,
            'reviewer_id' => $reviewer->id,
            'action' => 'approved',
            'comment' => $comment ?: null,
        ]);

        $this->configureMail($provider->tenant);
        Notification::route('mail', $provider->email)->notify(new ServiceProviderApproved($provider));
        Notification::send($this->staffRecipients($provider->tenant), new ServiceProviderActivity($provider, 'approved'));
    }

    public function reject(ServiceProvider $provider, User $reviewer, string $comment): void
    {
        $provider->update([
            'status' => 'rejected',
            'rejected_by' => $reviewer->id,
            'rejected_at' => now(),
        ]);

        ServiceProviderReview::create([
            'service_provider_id' => $provider->id,
            'reviewer_id' => $reviewer->id,
            'action' => 'rejected',
            'comment' => $comment,
        ]);

        $this->configureMail($provider->tenant);
        Notification::route('mail', $provider->email)->notify(new ServiceProviderRejected($provider, $comment));
        Notification::send($this->staffRecipients($provider->tenant), new ServiceProviderActivity($provider, 'rejected'));
    }

    private function configureMail(Tenant $tenant): void
    {
        app(TenantMailConfigurator::class)->apply($tenant);
    }

    private function storeDocuments(ServiceProvider $provider, array $files): void
    {
        $required = [
            'trade_license_document' => 'trade_license',
            'emirates_id_document' => 'emirates_id',
        ];

        foreach ($required as $field => $type) {
            if (($file = $files[$field] ?? null) !== null) {
                $this->storeDocument($provider, $file, $type);
            }
        }

        foreach ($files['additional_documents'] ?? [] as $file) {
            $this->storeDocument($provider, $file, 'other');
        }
    }

    private function replaceDocuments(ServiceProvider $provider, array $files): void
    {
        foreach ($provider->documents as $document) {
            Storage::disk(config('filesystems.default'))->delete($document->path);
            $document->delete();
        }

        $this->storeDocuments($provider, $files);
    }

    private function storeDocument(ServiceProvider $provider, $file, string $type): void
    {
        $path = $file->store("service-providers/{$provider->id}", config('filesystems.default'));

        ServiceProviderDocument::create([
            'service_provider_id' => $provider->id,
            'doc_type' => $type,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
