<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Lead;
use App\Models\Property;
use App\Models\User;

class UnitLeadAssignmentService
{
    /**
     * The unit's owner, when the unit opts in to receiving its leads.
     *
     * Single source of truth for the guard: a portal lead may be routed here
     * before the lead row exists, so the rule cannot live inside the assign
     * method alone.
     */
    public function unitOwner(?Property $property, int $tenantId): ?User
    {
        if ($property === null) {
            return null;
        }

        if (! $property->assign_leads_to_owner) {
            return null;
        }

        if (! $property->assigned_agent_id) {
            return null;
        }

        return User::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($property->assigned_agent_id)
            ->receivingLeads()
            ->first();
    }

    /**
     * Move the lead onto the unit owner. Returns true when this call changed
     * the lead's agent, so a caller can tell the takeover from a no-op.
     *
     * The assignment is always written to the audit log with agent_id in
     * new_values: AssignmentHistoryService builds the lead's history from that
     * column alone, so a silent save here leaves no trace of who took the lead.
     */
    public function assignToUnitOwnerIfRequired(Lead $lead, ?Property $property = null): bool
    {
        $agent = $this->unitOwner($property, $lead->tenant_id);

        if ($agent === null) {
            return false;
        }

        if ($lead->agent_id === $agent->id) {
            return false;
        }

        $previous = $lead->agent_id;

        $lead->agent_id = $agent->id;
        $lead->save();

        AuditLog::log('lead.updated', $lead, ['agent_id' => $previous], [
            'agent_id' => $agent->id,
            'previous_agent_id' => $previous,
            'property_id' => $property?->id,
            'reason' => 'unit_owner',
        ]);

        return true;
    }

    public function reassignToUnitOwnerIfAnyLinkedRequiresIt(Lead $lead): void
    {
        $property = $lead->properties()
            ->where('properties.tenant_id', $lead->tenant_id)
            ->where('assign_leads_to_owner', true)
            ->whereNotNull('assigned_agent_id')
            ->latest('lead_property.created_at')
            ->first();

        if (! $property) {
            return;
        }

        $this->assignToUnitOwnerIfRequired($lead, $property);
    }
}
