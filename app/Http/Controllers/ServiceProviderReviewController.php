<?php

namespace App\Http\Controllers;

use App\Models\ServiceProvider;
use App\Services\ServiceProviderService;
use Illuminate\Http\Request;

class ServiceProviderReviewController extends Controller
{
    public function __construct(private ServiceProviderService $service) {}

    public function index(Request $request)
    {
        $query = ServiceProvider::with(['documents', 'reviews.reviewer']);

        if ($request->filled('status') && array_key_exists($request->input('status'), ServiceProvider::STATUSES)) {
            $query->where('status', $request->input('status'));
        }

        $providers = $query->orderByDesc('updated_at')->paginate(20)->withQueryString();

        return view('service-providers.review', [
            'providers' => $providers,
            'statuses' => ServiceProvider::STATUSES,
            'counts' => ServiceProvider::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function store(Request $request, ServiceProvider $provider)
    {
        $data = $request->validate([
            'action' => ['required', 'in:approved,rejected,changes_requested'],
            'comment' => ['nullable', 'string', 'required_if:action,rejected', 'max:2000'],
        ]);

        $comment = $data['comment'] ?? null;

        match ($data['action']) {
            'approved' => $this->service->approve($provider, $request->user(), $comment),
            'rejected' => $this->service->reject($provider, $request->user(), $comment),
            'changes_requested' => $this->service->requestChanges($provider, $request->user(), $comment ?: 'Please review and update your application.'),
        };

        return redirect()->route('service-providers.review')
            ->with('success', 'Application updated and the service provider has been notified.');
    }
}
