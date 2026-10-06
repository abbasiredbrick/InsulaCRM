<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GeneralSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:100',
            'website' => 'nullable|string|max:255',
            'google_maps_embed_key' => 'nullable|string|max:255',
            'letterhead_display' => 'nullable|in:logo,name,both',
            'timezone' => 'nullable|string|max:100',
            'currency' => 'nullable|string|max:3',
            'date_format' => 'nullable|string|max:20',
            'country' => 'nullable|string|max:2',
            'measurement_system' => 'nullable|in:imperial,metric',
            'locale' => 'nullable|string|max:10',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png,gif|max:2048',
            'signature' => 'nullable|image|mimes:jpg,jpeg,png|max:4096',
            'stamp' => 'nullable|image|mimes:jpg,jpeg,png|max:4096',
            'iban_letter' => 'nullable|image|mimes:jpg,jpeg,png|max:4096',
            'bank_details' => 'nullable|string|max:5000',
        ];
    }
}
