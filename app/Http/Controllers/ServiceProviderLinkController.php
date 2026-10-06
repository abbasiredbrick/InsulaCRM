<?php

namespace App\Http\Controllers;

use App\Models\ServiceProviderRegistrationLink;
use App\Services\ServiceProviderService;
use Illuminate\Http\Request;

class ServiceProviderLinkController extends Controller
{
    public function __construct(private ServiceProviderService $service) {}

    public function index()
    {
        $links = ServiceProviderRegistrationLink::with('tenant')
            ->where('tenant_id', auth()->user()->tenant_id)
            ->orderByDesc('created_at')
            ->get();

        return view('service-providers.links', ['links' => $links]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'provider_email' => ['nullable', 'email:rfc', 'max:190'],
            'provider_name' => ['nullable', 'string', 'max:120'],
            'internal_note' => ['nullable', 'string', 'max:255'],
        ]);

        $link = $this->service->createRegistrationLink(
            $request->user()->tenant,
            $request->user(),
            $data,
        );

        return redirect()->route('service-providers.links')
            ->with('success', 'Registration link created. Send it to the service provider.')
            ->with('created_link_url', $link->url());
    }

    public function send(Request $request, ServiceProviderRegistrationLink $link)
    {
        abort_unless($link->tenant_id === auth()->user()->tenant_id, 403);

        $email = $request->validate(['provider_email' => ['required', 'email:rfc', 'max:190']])['provider_email'];

        $this->service->invite($link, $email);

        return redirect()->back()->with('success', 'Invitation email sent to '.$email.'.');
    }

    public function revoke(ServiceProviderRegistrationLink $link)
    {
        abort_unless($link->tenant_id === auth()->user()->tenant_id, 403);

        $link->update(['revoked_at' => now()]);

        return redirect()->back()->with('success', 'Registration link revoked.');
    }
}
