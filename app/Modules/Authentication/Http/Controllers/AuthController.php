<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\DTOs\LoginData;
use App\Modules\Authentication\DTOs\RegisterData;
use App\Modules\Authentication\Http\Requests\LoginRequest;
use App\Modules\Authentication\Http\Requests\RegisterRequest;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Services\AuthenticationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function __construct(private readonly AuthenticationService $authentication) {}

    public function register(RegisterRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->authentication->register(RegisterData::from($request->validated())),
            'Registration completed',
            status: 201
        );
    }

    public function login(LoginRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->authentication->login(LoginData::from($request->validated(), $request->ip(), $request->userAgent())),
            'Login completed'
        );
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return ApiResponse::success($this->authentication->profile($user), 'Authenticated user loaded');
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authentication->logout($user);

        return ApiResponse::success(null, 'Logged out');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->authentication->logoutAll($user);

        return ApiResponse::success(null, 'Logged out from all devices');
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'confirmed', 'min:10', 'regex:/[a-z]/', 'regex:/[A-Z]/', 'regex:/[0-9]/', 'regex:/[^A-Za-z0-9]/'],
        ]);

        return ApiResponse::success(
            $this->authentication->changePassword($request->user(), $data['current_password'], $data['password']),
            'Password changed'
        );
    }
}
