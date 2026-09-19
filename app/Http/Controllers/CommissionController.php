<?php

namespace App\Http\Controllers;

use App\Models\LeadCommission;
use App\Models\Lead;
use Illuminate\Http\Request;

class CommissionController extends Controller
{
    /**
     * The current member's commission ledger across every lead they shared.
     */
    public function mine(Request $request)
    {
        $user = auth()->user();

        $commissions = LeadCommission::with(['lead.agent'])
            ->where('agent_id', $user->id)
            ->orderByDesc('commissioned_at')
            ->orderByDesc('created_at')
            ->get();

        $earned = $commissions->where('status', LeadCommission::STATUS_EARNED);
        $paid = $commissions->where('status', LeadCommission::STATUS_PAID);

        $balance = (float) $earned->sum(fn ($c) => (float) $c->amount);

        return view('commissions.mine', compact('commissions', 'earned', 'paid', 'balance'));
    }

    /**
     * Admin only: move a commission row between earned, paid and void.
     */
    public function updateStatus(Request $request, LeadCommission $commission)
    {
        if (! auth()->user()->isAdmin()) {
            abort(403);
        }

        $data = $request->validate([
            'status' => 'required|in:earned,paid,void',
        ]);

        $commission->update([
            'status' => $data['status'],
            'paid_at' => $data['status'] === LeadCommission::STATUS_PAID ? now() : null,
        ]);

        $lead = $commission->lead;

        \App\Models\AuditLog::log('commission.status_changed', $commission, ['status' => $data['status']]);

        return back()->with('success', __('Commission status updated.'));
    }

    /**
     * Admin helper: link a lead commission row to the lead it belongs to.
     */
    public function show(LeadCommission $commission)
    {
        $lead = $commission->lead;

        if ($lead) {
            return redirect()->route('leads.show', $lead);
        }

        return back()->with('error', __('Lead not found.'));
    }
}