<?php

namespace App\Http\Requests\Marketplace;

use App\Services\TenantContext;
use Illuminate\Foundation\Http\FormRequest;

class CollectMarketplaceCodPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        if (! $user || ! $user->isActive()) {
            return false;
        }
        if ($user->isSuperAdmin()) {
            return true;
        }

        try {
            $context = app(TenantContext::class);

            return $context->membership()->user_id === $user->id
                && $context->membership()->role?->name === 'business_admin';
        } catch (\Throwable) {
            return false;
        }
    }

    public function rules(): array
    {
        return [
            'amount_received' => ['nullable', 'numeric', 'gt:0'],
            'received_at' => ['nullable', 'date'],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'verification_note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
            'collection_source' => ['nullable', 'string', 'in:business_cash,courier_reconciled'],
            'courier_reference' => ['nullable', 'string', 'max:150', 'required_if:collection_source,courier_reconciled'],
        ];
    }
}
