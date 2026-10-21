<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class RegisterBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'business_code' => strtoupper(trim((string) $this->input('business_code'))),
            'business_email' => strtolower(trim((string) $this->input('business_email'))),
            'owner_email' => strtolower(trim((string) $this->input('owner_email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:255'],
            'business_code' => ['required', 'string', 'max:50', 'regex:/^[A-Z][A-Z0-9_-]{2,49}$/', Rule::unique('tenants', 'business_code')],
            'business_email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'country' => ['nullable', 'string', 'max:100'],
            'currency_code' => ['nullable', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['nullable', 'timezone'],
            'business_type' => ['nullable', Rule::in(['company', 'personal'])],
            'id_type' => ['nullable', Rule::in(['NRC', 'passport', 'company_registration', 'other'])],
            'id_number' => ['nullable', 'string', 'max:150'],
            'company_registration_number' => ['nullable', 'string', 'max:150', 'required_if:business_type,company'],
            'personal_registration_number' => ['nullable', 'string', 'max:150', 'required_if:business_type,personal'],
            'business_logo' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)],
            'admin_profile_image' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)],
            'id_front_image' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)],
            'id_back_image' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)],
            'company_certificate_image' => ['nullable', File::image()->types(['jpg', 'jpeg', 'png', 'webp'])->max(5120)],
            'owner_name' => ['required', 'string', 'max:255'],
            'owner_email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', 'string', 'min:12'],
        ];
    }

    public function messages(): array
    {
        return [
            'business_code.unique' => 'This business code is already registered. Choose another code.',
            'owner_email.unique' => 'This owner email is already registered. Use the existing account or choose another email.',
        ];
    }
}
