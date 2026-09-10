<?php

namespace App\Http\Requests;

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
        $leadStatuses = implode(',', CustomFieldService::getValidSlugs('lead_status'));

        return [
            'type' => "required|in:{$activityTypes},stage_change",
            'subject' => 'nullable|string|max:255',
            'body' => 'nullable|string',
            'status' => "nullable|in:{$leadStatuses}",
            'temperature' => 'nullable|in:hot,warm,cold',
        ];
    }
}
