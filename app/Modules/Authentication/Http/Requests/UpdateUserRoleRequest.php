<?php

namespace App\Modules\Authentication\Http\Requests;

use App\Modules\Authentication\Services\UserManagementService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRoleRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::in(app(UserManagementService::class)->assignableRoles($this->user()))],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ];
    }
}
