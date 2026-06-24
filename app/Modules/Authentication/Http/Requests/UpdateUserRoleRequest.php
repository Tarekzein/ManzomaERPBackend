<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Modules\Authentication\Services\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    public function rules(): array
    {
        $service = app(UserManagementService::class);
        $roles = $service->assignableRoleNames($this->user());
        $permissions = $service->assignablePermissionNames($this->user());

        return [
            'role' => ['required', Rule::in($roles)],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['required', 'string', Rule::in($permissions)],
            'allowed_permissions' => ['sometimes', 'array'],
            'allowed_permissions.*' => ['required', 'string', Rule::in($permissions)],
            'denied_permissions' => ['sometimes', 'array'],
            'denied_permissions.*' => ['required', 'string', Rule::in($permissions)],
        ];
    }
}
