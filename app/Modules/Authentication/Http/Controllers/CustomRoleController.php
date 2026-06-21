<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Services\CustomRoleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomRoleController extends Controller
{
    public function __construct(private readonly CustomRoleService $roles) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->roles->list($request->user()), 'Custom roles loaded');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success($this->roles->save($request->user(), $this->validateRole($request)), 'Custom role created', status: 201);
    }

    public function update(Request $request, CompanyCustomRole $role): JsonResponse
    {
        return ApiResponse::success($this->roles->save($request->user(), $this->validateRole($request, $role), $role), 'Custom role updated');
    }

    public function destroy(Request $request, CompanyCustomRole $role): JsonResponse
    {
        $this->roles->delete($request->user(), $role);

        return ApiResponse::success(null, 'Custom role deleted');
    }

    public function assign(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['custom_role_id' => ['required', 'integer', 'exists:company_custom_roles,id']]);

        return ApiResponse::success(
            $this->roles->assign($request->user(), $user, CompanyCustomRole::findOrFail($data['custom_role_id'])),
            'Custom role assigned'
        );
    }

    private function validateRole(Request $request, ?CompanyCustomRole $role = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('company_custom_roles')->where('company_id', $request->user()->company_id)->ignore($role)],
            'description' => ['nullable', 'string'],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['required', 'string', 'exists:permissions,name'],
        ]);
    }
}
