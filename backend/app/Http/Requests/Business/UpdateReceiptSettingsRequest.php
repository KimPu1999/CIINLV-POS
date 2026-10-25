<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;

class UpdateReceiptSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'revision' => ['sometimes', 'integer', 'min:1'],
            'receipt_business_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'header_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'footer_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'paper_width' => ['sometimes', 'in:58,80,a4'],
            'show_logo' => ['sometimes', 'boolean'],
            'show_address' => ['sometimes', 'boolean'],
            'show_phone' => ['sometimes', 'boolean'],
            'show_tax' => ['sometimes', 'boolean'],
            'show_cashier' => ['sometimes', 'boolean'],
            'show_datetime' => ['sometimes', 'boolean'],
            'currency_display' => ['sometimes', 'boolean'],
            'auto_print' => ['sometimes', 'boolean'],
            'show_receipt_preview' => ['sometimes', 'boolean'],
        ];
    }
}
