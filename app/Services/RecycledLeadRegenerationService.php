<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\RecycledLead;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The regeneration step: a contact who responded positively in the Recycled
 * Leads pool becomes an active lead again.
 *
 * - Auto-recycled records revive their ORIGINAL lead (history, reference and
 *   notes stay intact and the pipeline motion reappears in the activity feed).
 * - CSV-imported records (no original lead) create a fresh lead sourced from
 *   the original portal.
 *
 * The chosen intent drives the deal type, contact type and, for handover
 * tracking, an automated follow-up task dated to the unit's handover.
 */
class RecycledLeadRegenerationService
{
    public function regenerate(RecycledLead $recycled, string $intent, ?int $agentId = null, ?string $notes = null, ?string $handoverDate = null): Lead
    {
        $config = RecycledLead::intent($intent);

        if ($config === null) {
            throw new RuntimeException('Invalid regeneration intent.');
        }

        if ($recycled->status === 'regenerated') {
            throw new RuntimeException('This contact was already regenerated.');
        }

        if ($recycled->status === 'already_active') {
            throw new RuntimeException('This contact is already an active lead — open the linked lead instead of regenerating.');
        }

        if ($recycled->status === 'do_not_contact') {
            throw new RuntimeException('This contact asked not to be contacted.');
        }

        $actingUser = auth()->user();
        $tenantId = $recycled->tenant_id;
        $agentId = $agentId ?: ($recycled->assignee_id ?: ($actingUser ? $actingUser->id : null));

        if (! $agentId) {
            throw new RuntimeException('Pick an agent to regenerate the lead under.');
        }

        if ($config['tracks_handover']) {
            if ($handoverDate) {
                $recycled->update(['expected_handover_date' => $handoverDate]);
            }

            if (! $recycled->expected_handover_date) {
                throw new RuntimeException('Handover tracking needs an expected handover date for the purchased unit.');
            }
        }

        return DB::transaction(function () use ($recycled, $config, $agentId, $notes): Lead {
            $lead = $recycled->original_lead_id !== null
                ? $this->reviveOriginalLead($recycled, $config, $agentId, $notes)
                : $this->createFreshLead($recycled, $config, $agentId, $notes);

            if ($config['tracks_handover']) {
                $this->scheduleHandoverTask($recycled, $lead, $agentId);
            }

            $recycled->forceFill([
                'status' => 'regenerated',
                'regeneration_intent' => $config['key'],
                'assignee_id' => $agentId,
                'regenerated_lead_id' => $lead->id,
                'last_contacted_at' => now(),
            ])->save();

            $recycled->regenerated_lead_id = $lead->id;

            $this->logRegenerationActivity($lead, $recycled, $config, $notes);
            AuditLog::log('recycled.regenerated', $recycled, [
                'intent' => $config['key'],
                'lead_id' => $lead->id,
                'agent_id' => $agentId,
            ]);

            return $lead;
        });
    }

    /**
     * Bring the original (auto-recycled) lead back into the live pipeline.
     */
    protected function reviveOriginalLead(RecycledLead $recycled, array $config, int $agentId, ?string $notes): Lead
    {
        $lead = $recycled->originalLead;

        if (! $lead) {
            throw new RuntimeException('The original lead no longer exists.');
        }

        $custom = $lead->custom_fields ?? [];
        $custom['recycled_regenerated'] = true;
        $custom['recycling_intent'] = $config['key'];
        $custom['recycled_on'] = $recycled->recycled_at?->toDateString();
        $custom['previous_deal_type'] = $lead->deal_type;

        $context = trim(implode(' ', array_filter([
            "Regenerated from Recycled Leads ({$config['label']}).",
            $notes ? "Regeneration notes: {$notes}" : null,
        ])));

        $lead->forceFill([
            'status' => 'new',
            'status_changed_at' => now(),
            'temperature' => 'warm',
            'deal_type' => $config['deal_type'],
            'stage' => 'new_lead',
            'stage_changed_at' => now(),
            'contact_type' => $config['contact_type'],
            'agent_id' => $agentId,
            'recycled_at' => null,
            'custom_fields' => $custom,
            'notes' => trim($context.' '.trim((string) $lead->notes)),
        ])->save();

        return $lead->refresh();
    }

    /**
     * Create a brand-new lead from a CSV-imported pool record, sourced from
     * the original portal so reporting keeps the provenance.
     */
    protected function createFreshLead(RecycledLead $recycled, array $config, int $agentId, ?string $notes): Lead
    {
        $context = trim(implode(' ', array_filter([
            "Recycled Leads regeneration — previous portal contact ({$recycled->portal_label}).",
            $config['label'].'.',
            $recycled->reference ? "Previous portal reference: {$recycled->reference}." : null,
            $recycled->purchased_project ? "Purchased project: {$recycled->purchased_project}".($recycled->unit_no ? " (Unit {$recycled->unit_no})" : '').'.' : null,
            $recycled->expected_handover_date ? 'Expected handover: '.$recycled->expected_handover_date->format('M j, Y').'.' : null,
            $notes ? "Regeneration notes: {$notes}" : null,
        ])));

        return Lead::create([
            'tenant_id' => $recycled->tenant_id,
            'agent_id' => $agentId,
            'first_name' => $recycled->first_name ?: 'Unknown',
            'last_name' => $recycled->last_name,
            'phone' => $recycled->phone,
            'email' => $recycled->email,
            'lead_source' => $recycled->portal ?? 'other',
            'status' => 'new',
            'contact_type' => $config['contact_type'],
            'temperature' => 'warm',
            'deal_type' => $config['deal_type'],
            'stage' => 'new_lead',
            'notes' => $context,
            'custom_fields' => array_filter([
                'recycled_regenerated' => true,
                'recycling_intent' => $config['key'],
                'previous_portal_reference' => $recycled->reference,
                'purchased_project' => $recycled->purchased_project,
                'expected_handover_date' => $recycled->expected_handover_date?->toDateString(),
            ]),
        ]);
    }

    /**
     * Schedule the handover check-in: a task ~7 days before the buyer takes
     * over their nearly-ready unit, so the agent re-engages them for leasing,
     * listing or property management the moment it matters.
     */
    protected function scheduleHandoverTask(RecycledLead $recycled, Lead $lead, int $agentId): void
    {
        $handover = $recycled->expected_handover_date;

        if (! $handover) {
            return;
        }

        $due = $handover->copy()->subDays(7);
        if ($due->isPast()) {
            $due = now()->startOfDay();
        }

        $place = trim("{$recycled->purchased_project}".($recycled->unit_no ? " — Unit {$recycled->unit_no}" : '')) ?: 'their unit';

        $task = Task::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $agentId,
            'created_by' => $agentId,
            'title' => "Handover check-in — {$place} (handover {$handover->format('M j, Y')})",
            'due_date' => $due->toDateString(),
            'due_time' => null,
            'is_completed' => false,
            'status' => 'scheduled',
        ]);

        Activity::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $agentId,
            'type' => 'task',
            'subject' => 'Handover follow-up scheduled',
            'body' => "Remembered for the handover of {$place} around {$handover->format('M j, Y')} — follow up to lease it out, list it, or offer property management.",
            'subject_type' => Task::class,
            'subject_id' => $task->id,
            'logged_at' => now(),
        ]);
    }

    protected function logRegenerationActivity(Lead $lead, RecycledLead $recycled, array $config, ?string $notes): void
    {
        $context = trim(implode(' ', array_filter([
            "Regenerated from the Recycled Leads pool — {$config['label']}.",
            $recycled->original_lead_id ? 'Revived the original lead (history preserved).' : "Previous portal: {$recycled->portal_label}.",
            $notes ? "Notes: {$notes}" : null,
        ])));

        Activity::create([
            'tenant_id' => $lead->tenant_id,
            'lead_id' => $lead->id,
            'agent_id' => $lead->agent_id,
            'type' => 'note',
            'subject' => 'Lead regenerated',
            'body' => Str::limit($context, 2000),
            'logged_at' => now(),
        ]);
    }
}
