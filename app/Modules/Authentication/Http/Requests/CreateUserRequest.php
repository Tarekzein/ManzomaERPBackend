<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Services\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
{
    public function rules(): array
    {
        $service = app(UserManagementService::class);
        $roles = $service->assignableRoleNames($this->user());
        $permissions = $service->assignablePermissionNames($this->user());

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'role' => ['required', Rule::in($roles)],
            // Only a super admin picks the company: everyone else creates the
            // user inside their own resolved workspace.
            'company_id' => [
                'nullable',
                Rule::requiredIf(fn () => $this->user()?->isSuperAdmin()
                    && $this->input('role') === UserRole::CompanyAdmin->value),
                'integer',
                'exists:companies,id',
            ],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', Rule::in($permissions)],
            'allowed_permissions' => ['sometimes', 'array'],
            'allowed_permissions.*' => ['required', 'string', Rule::in($permissions)],
            'denied_permissions' => ['sometimes', 'array'],
            'denied_permissions.*' => ['required', 'string', Rule::in($permissions)],
        ];
    }
}
