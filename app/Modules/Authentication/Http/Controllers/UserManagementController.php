<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\DTOs\CreateUserData;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Http\Requests\CreateUserRequest;
use App\Modules\Authentication\Http\Requests\UpdateUserRoleRequest;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Services\UserManagementService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function __construct(private readonly UserManagementService $users) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->users->list($request->user(), $request->integer('per_page', 15)),
            'Users loaded'
        );
    }

    public function roles(Request $request): JsonResponse
    {
        return ApiResponse::success($this->users->assignableRoles($request->user()), 'Assignable roles loaded');
    }

    public function store(CreateUserRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->users->create($request->user(), CreateUserData::from($request->validated())),
            'User created',
            status: 201
        );
    }

    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        return ApiResponse::success(
            $this->users->updateRole(
                $request->user(),
                $user,
                UserRole::from($request->validated('role')),
                $request->validated('company_id')
            ),
            'User role updated'
        );
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ]);

        return ApiResponse::success($this->users->update($request->user(), $user, $data), 'User updated');
    }

    public function activate(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->users->setActive($request->user(), $user, true), 'User activated');
    }

    public function deactivate(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->users->setActive($request->user(), $user, false), 'User deactivated');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->users->remove($request->user(), $user), 'User removed');
    }

    public function forcePasswordReset(Request $request, User $user): JsonResponse
    {
        return ApiResponse::success($this->users->forcePasswordReset($request->user(), $user), 'Password reset required');
    }
}
