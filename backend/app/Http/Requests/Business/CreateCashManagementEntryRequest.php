<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

class CreateCashManagementEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'source_type' => ['nullable', 'string', 'in:fund,register'],
            'source_id' => ['nullable', 'uuid'],
            'fund_id' => ['nullable', 'uuid', 'exists:cash_funds,uuid'],
            'register_id' => ['nullable', 'uuid'],
            'entry_type' => ['required', 'string', 'in:cash_in,cash_out,petty_expense'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'category' => ['nullable', 'string', 'max:80'],
            'paid_to' => ['nullable', 'string', 'max:255'],
            'received_from' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:3000'],
            'reference' => ['nullable', 'string', 'max:150'],
            'transaction_date' => ['nullable', 'date_format:Y-m-d'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'attachment' => ['nullable', File::types(['pdf', 'jpg', 'jpeg', 'png', 'webp'])->max(10240)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $sourceType = $this->input('source_type');
            $sourceId = $this->input('source_id');
            $legacySource = $this->input('fund_id') ?: $this->input('register_id');
            if ((! $sourceType || ! $sourceId) && ! $legacySource) {
                $validator->errors()->add('source_id', 'Select a register or petty cash account.');
            }
            if (($sourceType && ! $sourceId) || (! $sourceType && $sourceId)) {
                $validator->errors()->add('source_id', 'The selected payment source is invalid.');
            }
            if ($this->input('category') === 'fund_transfer') {
                $validator->errors()->add('category', 'Use Fund Transfer for transfers between cash accounts.');
            }
        });
    }
}
