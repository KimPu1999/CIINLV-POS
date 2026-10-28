<?php

namespace App\Http\Requests\Marketplace;

use Illuminate\Foundation\Http\FormRequest;

class MarketplaceCancellationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:2000']];
    }

    public function originalReason(): string
    {
        $payload = json_decode($this->getContent(), true);

        return is_array($payload) && is_string($payload['reason'] ?? null)
            ? $payload['reason']
            : (string) $this->input('reason');
    }
}
