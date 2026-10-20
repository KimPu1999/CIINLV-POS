<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBusinessSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['sometimes', 'integer', 'min:1'],
            'currency_code' => ['sometimes', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'timezone' => ['sometimes', 'timezone'],
            'locale' => ['sometimes', 'string', 'max:20'],
            'date_format' => ['sometimes', 'string', 'max:30'],
            'time_format' => ['sometimes', 'in:12,24'],
            'number_format' => ['sometimes', 'string', 'max:30'],
            'language' => ['sometimes', 'string', 'max:20'],
            'tax_configuration' => ['sometimes', 'array'],
            'receipt_configuration' => ['sometimes', 'array'],
            'pos_configuration' => ['sometimes', 'array', 'max:20'],
            'pos_configuration.cash_shift_required' => ['sometimes', 'boolean'],
            'pos_configuration.cash_payments_enabled' => ['sometimes', 'boolean'],
            'pos_configuration.card_payments_display' => ['sometimes', 'boolean', 'prohibited_if:pos_configuration.card_payments_display,true'],
            'pos_configuration.hold_resume_enabled' => ['sometimes', 'boolean'],
            'pos_configuration.discount_requires_approval' => ['sometimes', 'boolean'],
            'pos_configuration.discount_max_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'pos_configuration.discount_max_amount' => ['sometimes', 'numeric', 'min:0'],
            'pos_configuration.service_charge' => ['sometimes', 'array', 'max:10'],
            'pos_configuration.service_charge.enabled' => ['sometimes', 'boolean'],
            'pos_configuration.service_charge.type' => ['sometimes', 'in:percentage,fixed'],
            'pos_configuration.service_charge.value' => ['sometimes', 'numeric', 'min:0'],
            'pos_configuration.service_charge.taxable' => ['sometimes', 'boolean'],
            'pos_configuration.fees' => ['sometimes', 'array', 'max:20'],
            'pos_configuration.fees.*.code' => ['nullable', 'string', 'max:50'],
            'pos_configuration.fees.*.label' => ['required_with:pos_configuration.fees', 'string', 'max:120'],
            'pos_configuration.fees.*.enabled' => ['sometimes', 'boolean'],
            'pos_configuration.fees.*.type' => ['sometimes', 'in:percentage,fixed'],
            'pos_configuration.fees.*.value' => ['sometimes', 'numeric', 'min:0'],
            'pos_configuration.fees.*.taxable' => ['sometimes', 'boolean'],
            'pos_configuration.rounding' => ['sometimes', 'array', 'max:5'],
            'pos_configuration.rounding.mode' => ['sometimes', 'in:nearest,up,down'],
            'pos_configuration.rounding.increment' => ['sometimes', 'numeric', 'gt:0'],
            'pos_configuration.receipt_after_checkout' => ['sometimes', 'boolean'],
            'pos_configuration.customer_selection_enabled' => ['sometimes', 'boolean'],
            'pos_configuration.sale_confirmation_required' => ['sometimes', 'boolean'],
            'inventory_configuration' => ['sometimes', 'array', 'max:20'],
            'inventory_configuration.low_stock_alert_enabled' => ['sometimes', 'boolean'],
            'inventory_configuration.default_reorder_level' => ['sometimes', 'numeric', 'min:0', 'max:1000000000'],
            'inventory_configuration.target_stock_multiplier' => ['sometimes', 'numeric', 'gte:1', 'max:100'],
            'inventory_configuration.barcode_mode' => ['sometimes', 'in:sku,barcode,both'],
            'inventory_configuration.stock_count_frequency' => ['sometimes', 'in:daily,weekly,monthly,manual'],
            'payment_configuration' => ['sometimes', 'array', 'max:20'],
            'payment_configuration.enabled_methods' => ['sometimes', 'array', 'min:1', 'max:4'],
            'payment_configuration.enabled_methods.*' => ['string', 'in:cash'],
            'payment_configuration.tax_mode' => ['sometimes', 'in:inclusive,exclusive'],
            'payment_configuration.tax_rules' => ['sometimes', 'array', 'max:20'],
            'notification_configuration' => ['sometimes', 'array', 'max:20'],
            'notification_configuration.daily_summary_enabled' => ['sometimes', 'boolean'],
            'notification_configuration.weekly_summary_enabled' => ['sometimes', 'boolean'],
            'appearance_configuration' => ['sometimes', 'array', 'max:20'],
            'appearance_configuration.theme' => ['sometimes', 'in:light,dark,system'],
            'appearance_configuration.compact_tables' => ['sometimes', 'boolean'],
            'data_configuration' => ['sometimes', 'array', 'max:20'],
            'data_configuration.retention_note' => ['sometimes', 'string', 'max:500'],
        ];
    }
}
