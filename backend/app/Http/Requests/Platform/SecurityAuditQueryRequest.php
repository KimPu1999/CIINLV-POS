<?php

namespace App\Http\Requests\Platform;

use Illuminate\Foundation\Http\FormRequest;

class SecurityAuditQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isSuperAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'days' => ['nullable', 'integer', 'in:7,30,90,365'],
            'search' => ['nullable', 'string', 'max:100'],
            'action' => ['nullable', 'string', 'max:100'],
            'actor_id' => ['nullable', 'integer', 'exists:users,id'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
            'severity' => ['nullable', 'string', 'in:info,notice,warning,critical'],
            'outcome' => ['nullable', 'string', 'in:success,failure,blocked,unknown'],
            'resource_type' => ['nullable', 'string', 'max:255'],
            'event_id' => ['nullable', 'integer'],
            'correlation_id' => ['nullable', 'string', 'max:100'],
            'ip_address' => ['nullable', 'ip'],
            'sort' => ['nullable', 'in:created_at,id'],
            'direction' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
