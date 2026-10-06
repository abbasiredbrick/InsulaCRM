<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceProviderApplicationRequest;
use App\Models\ServiceProvider;
use App\Models\ServiceProviderRegistrationLink;
use App\Services\ServiceProviderService;

class PublicServiceProviderController extends Controller
{
    public function __construct(private ServiceProviderService $service) {}

    public function create(string $token)
    {
        $link = $this->findUsableLink($token);

        if (! $link->isUsable()) {
            return view('service-providers.public-invalid', ['state' => $link->state()]);
        }

        return view('service-providers.public-register', [
            'link' => $link,
            'action' => route('service-providers.public.store', $token),
        ]);
    }

    public function store(string $token, StoreServiceProviderApplicationRequest $request)
    {
        $link = $this->findUsableLink($token);

        if (! $link->isUsable()) {
            return view('service-providers.public-invalid', ['state' => $link->state()]);
        }

        $provider = $this->service->register($link, $request->validated(), $request->allFiles());

        return redirect()->route('service-providers.public.status', $provider->edit_token)
            ->with('success', 'Your registration has been submitted for review.');
    }

    public function status(string $editToken)
    {
        $provider = $this->findProvider($editToken);

        return view('service-providers.public-status', ['provider' => $provider]);
    }

    public function edit(string $editToken)
    {
        $provider = $this->findProvider($editToken);

        if (! $provider->isUnderReview()) {
            return redirect()->route('service-providers.public.status', $provider->edit_token);
        }

        return view('service-providers.public-edit', [
            'provider' => $provider,
            'action' => route('service-providers.public.update', $provider->edit_token),
            'comment' => $provider->reviews->firstWhere('action', 'changes_requested')?->comment,
        ]);
    }

    public function update(string $editToken, StoreServiceProviderApplicationRequest $request)
    {
        $provider = $this->findProvider($editToken);

        if (! $provider->isUnderReview()) {
            return redirect()->route('service-providers.public.status', $provider->edit_token);
        }

        $this->service->resubmit($provider, $request->validated(), $request->allFiles());

        return redirect()->route('service-providers.public.status', $provider->edit_token)
            ->with('success', 'Your updated application has been submitted for review.');
    }

    private function findUsableLink(string $token): ServiceProviderRegistrationLink
    {
        return ServiceProviderRegistrationLink::with('tenant')
            ->where('token', $token)
            ->firstOrFail();
    }

    private function findProvider(string $editToken): ServiceProvider
    {
        return ServiceProvider::with(['documents', 'reviews.reviewer'])
            ->where('edit_token', $editToken)
            ->firstOrFail();
    }
}
