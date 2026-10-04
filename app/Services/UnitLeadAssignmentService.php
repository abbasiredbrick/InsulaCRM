<?php

namespace App\Services;

use App\Models\Lead;
use App\Models\Property;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class UnitLeadAssignmentService
{
    public function assignToUnitOwnerIfRequired(Lead $lead, ?Property $property = null): void
    {
        if ($property === null) {
            return;
        }

        if (! $property->assign_leads_to_owner) {
            return;
        }

        if (! $property->assigned_agent_id) {
            return;
        }

        $agent = User::withoutGlobalScopes()
            ->where('tenant_id', $lead->tenant_id)
            ->where('id', $property->assigned_agent_id)
            ->where('is_active', true)
            ->where('receives_leads', true)
            ->first();

        if (! $agent) {
            return;
        }

        $lead->agent_id = $agent->id;
        $lead->save();
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
