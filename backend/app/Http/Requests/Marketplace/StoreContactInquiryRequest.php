<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class StoreContactInquiryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'subject' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'website' => ['nullable', 'string', 'max:100'],
        ];
    }
}
