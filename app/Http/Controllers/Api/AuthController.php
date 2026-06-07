<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()->symbols()],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $company = Company::create([
            'name' => $validated['company_name'],
            'plan' => 'basic',
            'timezone' => config('app.timezone'),
            'locale' => config('app.locale'),
            'currency' => 'EGP',
            'is_active' => true,
        ]);

        $user = User::create([
            'company_id' => $company->id,
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        Role::findOrCreate('Company Admin');
        $user->assignRole('Company Admin');

        return ApiResponse::success([
            'user' => $user->load('company'),
            'token' => $user->createToken($validated['device_name'] ?? 'api')->plainTextToken,
        ], 'Registration completed', status: 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        $user = User::where('email', $validated['email'])->first();
        $success = $user !== null && Hash::check($validated['password'], $user->password);

        LoginAttempt::create([
            'user_id' => $user?->id,
            'email' => $validated['email'],
            'successful' => $success,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        if (! $success) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        return ApiResponse::success([
            'user' => $user->load('company'),
            'token' => $user->createToken($validated['device_name'] ?? 'api')->plainTextToken,
        ], 'Login completed');
    }

    public function me(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()->load('company', 'roles.permissions'),
            'Authenticated user loaded'
        );
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::success(null, 'Logged out');
    }

    public function logoutAll(Request $request): JsonResponse
    {
        $request->user()->tokens()->delete();

        return ApiResponse::success(null, 'Logged out from all devices');
    }
}
