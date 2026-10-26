<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBusinessInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['sometimes', 'integer', 'min:1'],
            'profile' => ['sometimes', 'array', 'max:30'],
            'profile.name' => ['sometimes', 'required', 'string', 'max:255'],
            'profile.display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'profile.description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'profile.business_type' => ['sometimes', 'nullable', Rule::in(['company', 'personal'])],
            'profile.email' => ['sometimes', 'nullable', 'email:rfc', 'max:255'],
            'profile.website' => ['sometimes', 'nullable', 'url:http,https', 'max:255'],
            'profile.phone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'profile.address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'profile.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.state' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.postal_code' => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile.country' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.id_type' => ['sometimes', 'nullable', Rule::in(['NRC', 'passport', 'company_registration', 'other'])],
            'profile.id_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'profile.company_registration_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'profile.personal_registration_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'profile.tax_registration_number' => ['sometimes', 'nullable', 'string', 'max:150'],
            'settings' => ['sometimes', 'array', 'max:20'],
            'settings.currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'settings.timezone' => ['sometimes', 'timezone'],
            'settings.locale' => ['sometimes', 'string', 'max:20'],
            'settings.tax_configuration' => ['sometimes', 'array', 'max:20'],
            'settings.tax_configuration.rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'settings.tax_configuration.default_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'settings.tax_configuration.mode' => ['sometimes', 'in:inclusive,exclusive'],
            'settings.payment_configuration' => ['sometimes', 'array', 'max:20'],
            'settings.payment_configuration.tax_mode' => ['sometimes', 'in:inclusive,exclusive'],
            'settings.pos_configuration' => ['sometimes', 'array', 'max:20'],
            'settings.pos_configuration.service_charge' => ['sometimes', 'array', 'max:10'],
            'settings.pos_configuration.service_charge.enabled' => ['sometimes', 'boolean'],
            'settings.pos_configuration.service_charge.type' => ['sometimes', 'in:percentage,fixed'],
            'settings.pos_configuration.service_charge.value' => ['sometimes', 'numeric', 'min:0'],
            'settings.pos_configuration.service_charge.taxable' => ['sometimes', 'boolean'],
            'settings.pos_configuration.fees' => ['sometimes', 'array', 'max:20'],
            'settings.pos_configuration.fees.*.code' => ['nullable', 'string', 'max:50'],
            'settings.pos_configuration.fees.*.label' => ['required_with:settings.pos_configuration.fees', 'string', 'max:120'],
            'settings.pos_configuration.fees.*.enabled' => ['sometimes', 'boolean'],
            'settings.pos_configuration.fees.*.type' => ['sometimes', 'in:percentage,fixed'],
            'settings.pos_configuration.fees.*.value' => ['sometimes', 'numeric', 'min:0'],
            'settings.pos_configuration.fees.*.taxable' => ['sometimes', 'boolean'],
            'settings.appearance_configuration' => ['sometimes', 'array', 'max:20'],
            'settings.appearance_configuration.primary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.appearance_configuration.secondary_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'settings.appearance_configuration.theme' => ['sometimes', 'in:light,dark,system'],
            'settings.data_configuration' => ['sometimes', 'array', 'max:20'],
            'settings.data_configuration.business_license_information' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'receipt_configuration' => ['sometimes', 'array', 'max:20'],
            'receipt_configuration.receipt_business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'receipt_configuration.header_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'receipt_configuration.footer_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'receipt_configuration.paper_width' => ['sometimes', 'in:58,80,a4'],
            'receipt_configuration.show_logo' => ['sometimes', 'boolean'],
            'receipt_configuration.show_address' => ['sometimes', 'boolean'],
            'receipt_configuration.show_phone' => ['sometimes', 'boolean'],
            'receipt_configuration.show_tax' => ['sometimes', 'boolean'],
            'receipt_configuration.show_cashier' => ['sometimes', 'boolean'],
            'receipt_configuration.show_datetime' => ['sometimes', 'boolean'],
            'receipt_configuration.currency_display' => ['sometimes', 'boolean'],
            'settlement' => ['sometimes', 'array', 'max:10'],
            'settlement.bank_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settlement.account_holder' => ['sometimes', 'nullable', 'string', 'max:255'],
            'settlement.account_details' => ['sometimes', 'nullable', 'string', 'max:500'],
            'settlement.settlement_method' => ['sometimes', 'nullable', 'in:bank_transfer,mobile_wallet,cash,other'],
            'clear_account_details' => ['sometimes', 'boolean'],
        ];
    }
}
