<?php

namespace App\Http\Controllers;

use App\Models\Buyer;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\Property;
use App\Services\BusinessModeService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function search(Request $request)
    {
        $q = trim($request->input('q', ''));
        $scope = strtolower($request->input('scope', 'all'));

        if (strlen($q) < 2) {
            return $request->expectsJson()
                ? response()->json(['results' => []])
                : view('search.results', ['query' => $q, 'results' => collect()]);
        }

        $user = auth()->user();
        $results = collect();
        $isRealEstate = BusinessModeService::isRealEstate();

        // Leads (supports scope=leads or all)
        if (in_array($scope, ['leads', 'all']) && $user->canManageLeads()) {
            $leads = Lead::where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            })
            ->when(!$user->isAdmin(), fn ($query) => $query->where(function ($q) use ($user) {
                $q->where('agent_id', $user->id)
                    ->orWhereHas('leadAgents', fn ($lq) => $lq->where('agent_id', $user->id)->where('status', \App\Models\LeadAgent::STATUS_ACTIVE));
            }))
            ->limit(5)
            ->get()
            ->map(fn ($lead) => [
                'type' => 'lead',
                'title' => $lead->full_name,
                'subtitle' => $lead->email ?? $lead->phone ?? '',
                'url' => route('leads.show', $lead),
            ]);

            $results = $results->merge($leads);
        }

        // Deals (scope=deals or all)
        if (in_array($scope, ['deals', 'all']) && !$user->isFieldScout()) {
            $deals = Deal::where(function ($outer) use ($q) {
                $outer->whereHas('lead', function ($query) use ($q) {
                    $query->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%");
                })
                ->orWhere('title', 'like', "%{$q}%")
                ->orWhere('notes', 'like', "%{$q}%");
            })
            ->when(!$user->isAdmin(), fn ($query) => $query->where('agent_id', $user->id))
            ->limit(5)
            ->get()
            ->map(fn ($deal) => [
                'type' => 'deal',
                'title' => $deal->lead->full_name ?? $deal->title,
                'subtitle' => \App\Models\Deal::stageLabel($deal->stage),
                'url' => route('deals.show', $deal),
            ]);

            $results = $results->merge($deals);
        }

        // Buyers (scope=buyers or all)
        if (in_array($scope, ['buyers', 'all']) && $user->canManageBuyers()) {
            $buyers = Buyer::where(function ($query) use ($q) {
                $query->where('first_name', 'like', "%{$q}%")
                    ->orWhere('last_name', 'like', "%{$q}%")
                    ->orWhere('company', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('phone', 'like', "%{$q}%");
            })
            ->limit(5)
            ->get()
            ->map(fn ($buyer) => [
                'type' => 'buyer',
                'title' => $buyer->full_name,
                'subtitle' => $buyer->company ?? $buyer->email ?? '',
                'url' => route('buyers.show', $buyer),
            ]);

            $results = $results->merge($buyers);
        }

        // Properties / inventory units (scope=inventory or all)
        if (in_array($scope, ['inventory', 'all'])) {
            $properties = Property::where(function ($query) use ($q) {
                $query->where('address', 'like', "%{$q}%")
                    ->orWhere('city', 'like', "%{$q}%")
                    ->orWhere('zip_code', 'like', "%{$q}%")
                    ->orWhere('marketing_title', 'like', "%{$q}%")
                    ->orWhere('community', 'like', "%{$q}%")
                    ->orWhere('sub_community', 'like', "%{$q}%")
                    ->orWhere('unit_no', 'like', "%{$q}%")
                    ->orWhere('developer_name', 'like', "%{$q}%")
                    ->orWhere('owner_name', 'like', "%{$q}%");
            })
            ->limit(5)
            ->get()
            ->map(fn ($property) => [
                'type' => 'property',
                'title' => $property->marketing_title ?: $property->address,
                'subtitle' => trim(($property->sub_community ?? $property->community ?? '') . ($property->unit_no ? ' #' . $property->unit_no : ''), ', '),
                'url' => $isRealEstate
                    ? route('inventory.show', $property)
                    : route('properties.show', $property),
            ]);

            $results = $results->merge($properties);
        }

        if ($request->expectsJson()) {
            return response()->json(['results' => $results->values()]);
        }

        return view('search.results', ['query' => $q, 'results' => $results]);
    }
}
