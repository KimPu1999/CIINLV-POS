<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_locale' => ['sometimes', 'in:en,my'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'business_type' => ['sometimes', 'nullable', Rule::in(['company', 'personal'])],
            'id_type' => ['sometimes', 'nullable', Rule::in(['NRC', 'passport', 'company_registration', 'other'])],
            'id_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'company_registration_number' => ['sometimes', 'nullable', 'string', 'max:150', 'required_if:business_type,company'],
            'personal_registration_number' => ['sometimes', 'nullable', 'string', 'max:150', 'required_if:business_type,personal'],
            'verification_status' => ['prohibited'],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'country' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
