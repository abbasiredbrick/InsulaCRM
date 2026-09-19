<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'title' => 'required|string|max:255',
            'due_date' => 'required|date',
            'due_time' => 'nullable|date_format:H:i',
            'assigned_to' => 'nullable|integer',
            'assign_to_user' => 'nullable|integer',
            'reminder_minutes' => 'nullable|integer|min:1|max:10080',
        ];

        if ($this->isMethod('post')) {
            $rules['due_date'] .= '|after_or_equal:today';
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Please enter a task title.',
            'due_date.required' => 'Please select a due date.',
            'due_date.after_or_equal' => 'Due date must be today or later.',
            'due_time.date_format' => 'Please enter a valid time.',
        ];
    }
}
