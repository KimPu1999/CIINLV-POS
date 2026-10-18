<?php

namespace App\Http\Requests\Business;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDashboardLayoutRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['sometimes', 'string', 'max:100'], 'layout' => ['required', 'array', 'max:100'], 'layout.version' => ['required', 'integer', 'in:1'], 'layout.widgets' => ['required', 'array', 'max:50'], 'layout.widgets.*.widget_key' => ['required', 'string', Rule::in(array_keys(config('business.dashboard_widget_keys', [])))], 'layout.widgets.*.position' => ['required', 'integer', 'min:0', 'max:1000'], 'layout.widgets.*.width' => ['required', 'integer', 'min:1', 'max:12'], 'layout.widgets.*.height' => ['required', 'integer', 'min:1', 'max:12']];
    }
}
