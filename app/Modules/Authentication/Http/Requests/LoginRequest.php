<?php

namespace App\Modules\Authentication\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
            'two_factor_code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:50'],
        ];
    }
}
