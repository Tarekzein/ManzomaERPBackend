<?php

namespace App\Modules\Subscriptions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SavePlanFeatureRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
