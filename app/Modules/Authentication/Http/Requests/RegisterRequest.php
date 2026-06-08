<?php

namespace App\Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'device_name' => ['nullable', 'string', 'max:255'],
            'plan_slug' => ['sometimes', 'string', Rule::exists('subscription_plans', 'slug')->where('is_active', true)],
            'billing_cycle' => ['sometimes', Rule::in(['monthly', 'annual'])],
        ];
    }
}
