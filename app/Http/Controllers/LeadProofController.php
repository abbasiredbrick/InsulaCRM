<?php

namespace App\Http\Controllers;

use App\Events\LeadStatusChanged;
use App\Facades\Hooks;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\LeadProof;
use App\Services\LeadStatusGate;
use App\Services\LostLeadNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * WhatsApp-communication proof for the lost/dead status gate. Uploading a
 * proof and marking the lead lost can happen in ONE request: the modal posts
 * the screenshot plus an optional target status, and the controller stores the
 * file, THEN applies the transition now that the proof exists. Uploading a
 * proof without a status is also allowed (prove early, mark later).
 */
class LeadProofController extends Controller
{
    public function store(Request $request, Lead $lead)
    {
        $this->authorize('update', $lead);

        $validated = $request->validate([
            'proof' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'max:10240'],
            'note' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', Rule::in(LeadStatusGate::PROOF_REQUIRED_STATUSES)],
        ]);

        $file = $request->file('proof');
        $path = $file->store("lead-proofs/{$lead->id}", 'local');

        $proof = LeadProof::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'uploaded_by' => auth()->id(),
            'type' => 'whatsapp_screenshot',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
            'note' => $validated['note'] ?? null,
        ]);

        AuditLog::log('lead.proof.uploaded', $lead, [
            'file' => $proof->original_name,
            'by' => auth()->user()->name,
        ]);

        // Same-request transition: the proof now exists, so the gate passes.
        if (! empty($validated['status']) && $validated['status'] !== $lead->status) {
            $oldStatus = $lead->status;
            $lead->update(['status' => $validated['status']]);

            event(new LeadStatusChanged($lead, $oldStatus));
            Hooks::doAction('lead.status_changed', $lead, $oldStatus);

            if (LostLeadNotifier::isLostStatus($lead->status)) {
                LostLeadNotifier::notify($lead, $lead->status, $oldStatus);
            }

            AuditLog::log('lead.status_changed', $lead, ['status' => $lead->status], ['status' => $oldStatus]);
        }

        return redirect()->route('leads.show', $lead)->with('success', __('Proof uploaded. This lead can now be marked as lost.'));
    }

    public function download(LeadProof $proof)
    {
        $this->authorize('update', $proof->lead);

        return Storage::disk('local')->download($proof->file_path, $proof->original_name);
    }

    public function destroy(LeadProof $proof)
    {
        $this->authorize('update', $proof->lead);

        Storage::disk('local')->delete($proof->file_path);
        AuditLog::log('lead.proof.deleted', $proof->lead, ['file' => $proof->original_name]);
        $proof->delete();

        return redirect()->route('leads.show', $proof->lead)->with('success', __('Proof deleted.'));
    }
}
