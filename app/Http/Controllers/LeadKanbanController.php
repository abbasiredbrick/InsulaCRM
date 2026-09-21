<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\User;
use App\Services\CustomFieldService;
use App\Services\LeadSearchService;
use Illuminate\Http\Request;

class LeadKanbanController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        $statuses = CustomFieldService::getOptions('lead_status');

        $search = app(LeadSearchService::class);

        $query = $search->applyVisibleScope(Lead::with(['agent', 'tags']), $user);

        if ($request->filled('agent_id')) {
            $agentId = (int) $request->agent_id;
            // Non-admins can only filter by an agent they can actually see.
            $viewableAgentIds = $search->visibleAgentIds($user);
            if ($viewableAgentIds === null || in_array($agentId, $viewableAgentIds, true)) {
                $query->where('agent_id', $agentId);
            }
        }

        if ($request->filled('search')) {
            $search->applyTerm($query, $request->search);
        }

        if ($request->filled('source')) {
            $query->where('lead_source', $request->source);
        }

        if ($request->filled('temperature')) {
            $query->where('temperature', $request->temperature);
        }

        $leads = $query->get()->groupBy('status');

        $agents = (! $user->isAgent() || $user->isManager()) ? $this->getAgents() : collect();

        return view('leads.kanban', compact('statuses', 'leads', 'agents'));
    }

    private function getAgents()
    {
        $user = auth()->user();

        if ($user->isManager()) {
            $teamIds = $user->teamUserIds();
            $teamIds[] = $user->id;

            return User::assignable($user->tenant)
                ->whereIn('id', $teamIds)
                ->where('is_active', true)
                ->orderBy('name')
                ->get();
        }

        return User::assignable($user->tenant)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
