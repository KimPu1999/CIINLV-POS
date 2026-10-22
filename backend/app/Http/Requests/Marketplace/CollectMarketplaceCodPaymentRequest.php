<?php

namespace App\Http\Requests\Marketplace;

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

        return $user->memberships()
            ->where('status', 'active')
            ->whereHas('role', fn ($role) => $role->where('name', 'business_admin'))
            ->exists();
    }

    public function rules(): array
    {
        return [
            'amount_received' => ['nullable', 'numeric', 'gt:0'],
            'received_at' => ['nullable', 'date'],
            'transaction_reference' => ['nullable', 'string', 'max:150'],
            'verification_note' => ['nullable', 'string', 'max:500'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
