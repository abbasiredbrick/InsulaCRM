<?php

namespace App\Http\Requests;

use App\Models\Lead;
use App\Services\CustomFieldService;
use Illuminate\Foundation\Http\FormRequest;

class ActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $activityTypes = implode(',', CustomFieldService::getValidSlugs('activity_type'));

        // The quick-log status mover mirrors LeadRequest: only the manual
        // (assignable) statuses may be posted, plus the lead's current status
        // so an unchanged field round-trips. closed_won/closed_lost are
        // deal-driven and never settable from the activity form.
        $statuses = CustomFieldService::getAssignableStatusSlugs();
        if ($current = $this->route('lead')?->status) {
            $statuses[] = $current;
        }
        $leadStatuses = implode(',', array_unique($statuses));

        return [
            'type' => "required|in:{$activityTypes},stage_change",
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string',
            'status' => "nullable|in:{$leadStatuses}",
            'temperature' => 'nullable|in:hot,warm,cold',
            'stage' => 'nullable|in:'.implode(',', Lead::allStageKeys()),
        ];
    }
}
