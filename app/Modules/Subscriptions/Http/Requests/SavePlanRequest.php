<?php

namespace App\Modules\Subscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePlanRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:100', Rule::unique('subscription_plans')->ignore($this->route('plan'))],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'monthly_price' => ['required', 'numeric', 'min:0'],
            'annual_price' => ['required', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'storage_gb' => ['nullable', 'integer', 'min:1'],
            'api_rate_limit_per_minute' => ['required', 'integer', 'min:1'],
            'trial_enabled' => ['sometimes', 'boolean'],
            'trial_days' => ['required_if:trial_enabled,true', 'integer', 'min:0', 'max:365'],
            'is_active' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
