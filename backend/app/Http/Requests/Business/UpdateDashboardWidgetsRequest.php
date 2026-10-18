<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardWidgetsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['widgets' => ['required', 'array', 'max:50'], 'widgets.*.widget_key' => ['required', Rule::in(array_keys(config('business.dashboard_widget_keys', [])))], 'widgets.*.title' => ['nullable', 'string', 'max:100'], 'widgets.*.position' => ['required', 'integer', 'min:0', 'max:1000'], 'widgets.*.width' => ['nullable', 'integer', 'min:1', 'max:12'], 'widgets.*.height' => ['nullable', 'integer', 'min:1', 'max:12'], 'widgets.*.is_enabled' => ['nullable', 'boolean'], 'widgets.*.settings' => ['nullable', 'array', 'max:20']];
    }
}
