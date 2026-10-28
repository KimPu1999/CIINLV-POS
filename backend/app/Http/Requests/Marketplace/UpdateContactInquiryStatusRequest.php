<?php

namespace App\Http\Requests\Marketplace;

use App\Models\MarketplaceContactInquiry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactInquiryStatusRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in([
            MarketplaceContactInquiry::STATUS_PENDING,
            MarketplaceContactInquiry::STATUS_IN_PROGRESS,
            MarketplaceContactInquiry::STATUS_RESOLVED,
            MarketplaceContactInquiry::STATUS_CLOSED,
        ])]];
    }
}
