<?php

namespace App\Http\Controllers;

use App\Models\ServiceProvider;
use App\Models\ServiceProviderDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ServiceProviderController extends Controller
{
    public function index(Request $request)
    {
        $query = ServiceProvider::approved()->with('documents');

        if ($term = $request->input('search')) {
            $query->where(function ($q) use ($term) {
                $q->where('company_name', 'like', "%{$term}%")
                    ->orWhere('representative_name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('mobile', 'like', "%{$term}%")
                    ->orWhere('city', 'like', "%{$term}%");
            });
        }

        if ($category = $request->input('category')) {
            $query->where('category', $category);
        }

        $providers = $query->orderBy('company_name')->paginate(15)->withQueryString();

        return view('service-providers.index', [
            'providers' => $providers,
            'categories' => ServiceProvider::CATEGORIES,
        ]);
    }

    public function show(ServiceProvider $provider)
    {
        $this->authorizeView($provider);

        $provider->load(['documents', 'reviews.reviewer', 'approvedBy', 'rejectedBy']);

        return view('service-providers.show', ['provider' => $provider]);
    }

    public function download(ServiceProviderDocument $document)
    {
        $provider = ServiceProvider::findOrFail($document->service_provider_id);
        $this->authorizeView($provider);

        return Storage::disk(config('filesystems.default'))
            ->download($document->path, $document->original_name);
    }

    private function authorizeView(ServiceProvider $provider): void
    {
        abort_unless($provider->isApproved() || auth()->user()->isAdmin(), 403);
    }
}
