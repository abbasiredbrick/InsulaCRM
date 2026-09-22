<?php

namespace App\Http\Controllers;

use App\Models\A2aContract;
use App\Models\DocumentTemplate;
use App\Models\GeneratedDocument;
use App\Models\OfferLetter;
use Illuminate\Http\Request;

/**
 * Unified "Documents & Agreements" hub (real estate mode).
 *
 * Brings the four document/agreement surfaces together under one nav item so
 * agents no longer hunt for them in separate menus:
 *  - Agreements: A2A commission-sharing contracts (lead- or property-scoped).
 *  - Offer Letters: brokerage offer letters linked to their deals.
 *  - Generated: documents rendered from the mergeable templates.
 *  - Templates (admin only): the shared {{merge.field}} template library.
 *
 * Non-admins only ever see their own records, mirroring the per-feature
 * scoping used by A2aContractController and the deals the user owns.
 */
class DocumentsHubController extends Controller
{
    protected const TABS = ['agreements', 'offers', 'generated', 'templates'];

    public function index(Request $request)
    {
        $user = auth()->user();

        $tab = $request->query('tab') ?: 'agreements';
        if (! in_array($tab, self::TABS, true)) {
            $tab = 'agreements';
        }

        if ($tab === 'templates' && ! $user->isAdmin()) {
            abort(403);
        }

        $data = ['tab' => $tab];

        switch ($tab) {
            case 'templates':
                $data['templates'] = DocumentTemplate::withCount('generatedDocuments')
                    ->orderBy('name')
                    ->get();
                break;

            case 'generated':
                $data['documents'] = GeneratedDocument::with(['template', 'user', 'deal'])
                    ->when(! $user->isAdmin(), fn ($q) => $q->where('user_id', $user->id))
                    ->latest()
                    ->get();
                break;

            case 'offers':
                $data['offerLetters'] = OfferLetter::with(['deal.lead', 'lead'])
                    ->when(! $user->isAdmin(), fn ($q) => $q->whereHas(
                        'deal',
                        fn ($dq) => $dq->where('agent_id', $user->id)
                    ))
                    ->latest()
                    ->get();
                break;

            default:
                $data['contracts'] = A2aContract::with('agent', 'lead', 'property')
                    ->when(! $user->isAdmin(), fn ($q) => $q->where('agent_id', $user->id))
                    ->latest()
                    ->get();
        }

        return view('documents.hub', $data);
    }
}
