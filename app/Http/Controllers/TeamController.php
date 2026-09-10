<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Deal;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    /**
     * Team progress overview for admins and managers.
     */
    public function index()
    {
        $user = auth()->user();
        abort_unless($user->isAdmin() || $user->isManager(), 403);

        $members = $user->isAdmin()
            ? User::with(['role', 'manager'])->where('tenant_id', $user->tenant_id)->orderBy('name')->get()
            : $user->allReports()->sortBy('name')->values();

        $monthStart = now()->startOfMonth();
        $monthEnd = now()->endOfMonth();

        foreach ($members as $member) {
            $member->open_leads = Lead::where('agent_id', $member->id)
                ->whereNotIn('status', ['closed_won', 'closed_lost', 'dead'])
                ->count();
            $member->closed_leads_this_month = Lead::where('agent_id', $member->id)
                ->where('status', 'closed_won')
                ->whereBetween('updated_at', [$monthStart, $monthEnd])
                ->count();
            $member->open_deals_value = (float) Deal::where('agent_id', $member->id)
                ->whereNotIn('stage', ['closed_won', 'closed_lost'])
                ->sum('contract_price');
            $member->closed_deals_this_month = Deal::where('agent_id', $member->id)
                ->where('stage', 'closed_won')
                ->whereBetween('created_at', [$monthStart, $monthEnd])
                ->count();
            $member->activities_7d = Activity::where('agent_id', $member->id)
                ->where('logged_at', '>=', now()->subDays(7))
                ->count();
            $member->last_activity_at = Activity::where('agent_id', $member->id)
                ->latest('logged_at')
                ->value('logged_at');
        }

        $totals = (object) [
            'open_leads' => (int) $members->sum('open_leads'),
            'closed_leads' => (int) $members->sum('closed_leads_this_month'),
            'open_deals_value' => (float) $members->sum('open_deals_value'),
            'closed_deals' => (int) $members->sum('closed_deals_this_month'),
            'activities_7d' => (int) $members->sum('activities_7d'),
        ];

        return view('team.index', compact('members', 'totals'));
    }

    /**
     * Admin-only: move a team member under a different manager.
     */
    public function setManager(Request $request)
    {
        $user = auth()->user();
        abort_unless($user->isAdmin(), 403);

        $data = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'reports_to' => 'nullable|integer',
        ]);

        $member = User::where('tenant_id', $user->tenant_id)->findOrFail($data['user_id']);

        $reportsTo = null;
        if (! empty($data['reports_to'])) {
            $reportsTo = User::where('tenant_id', $user->tenant_id)->findOrFail($data['reports_to']);
            abort_if($reportsTo->id === $member->id, 422, __('A team member cannot report to themselves.'));
            // Avoid broken loops: the new manager cannot be one of the member's own reports.
            abort_if($member->managesUser($reportsTo), 422, __('That would create a reporting loop.'));
        }

        $member->update(['reports_to' => $reportsTo?->id]);

        AuditLog::log('team.reports_to_changed', $member, ['reports_to' => $reportsTo?->id]);

        return back()->with('success', __(':name now reports to :manager.', [
            'name' => $member->name,
            'manager' => $reportsTo?->name ?? __('no manager'),
        ]));
    }
}