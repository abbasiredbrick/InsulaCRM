<?php

namespace App\Http\Requests;

use App\Models\ServiceProvider;
use Illuminate\Foundation\Http\FormRequest;

class StoreServiceProviderApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:120'],
            'category' => ['required', 'in:'.implode(',', array_keys(ServiceProvider::CATEGORIES))],
            'trade_license_number' => ['required', 'string', 'max:60'],
            'services_offered' => ['nullable', 'string', 'max:1000'],
            'representative_name' => ['required', 'string', 'max:120'],
            'representative_emirates_id' => ['required', 'string', 'max:30', 'regex:/^([0-9]{15}|[0-9]{3}[- ][0-9]{4}[- ][0-9]{7}[- ][0-9])$/'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'mobile' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-\s()]{7,30}$/'],
            'city' => ['nullable', 'string', 'max:120'],
            'website' => ['nullable', 'url:http,https', 'max:255'],
            'trade_license_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'emirates_id_document' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'additional_documents' => ['nullable', 'array', 'max:3'],
            'additional_documents.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'representative_emirates_id.regex' => 'Enter the Emirates ID as 15 digits (e.g. 784-1998-1234567-1).',
            'trade_license_document.required' => 'Upload a copy of the trade license.',
            'emirates_id_document.required' => 'Upload a copy of the representative Emirates ID.',
        ];
    }
}
