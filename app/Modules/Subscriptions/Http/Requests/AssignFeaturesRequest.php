<?php

namespace App\Modules\Subscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignFeaturesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'features' => ['required', 'array'],
            'features.*.feature_id' => ['required', 'integer', 'distinct', 'exists:subscription_features,id'],
            'features.*.enabled' => ['sometimes', 'boolean'],
            'features.*.value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
