<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Modules\Authentication\Services\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in(app(UserManagementService::class)->assignableRoles($this->user()))],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', Rule::in(app(UserManagementService::class)->assignablePermissions($this->user()))],
        ];
    }
}
