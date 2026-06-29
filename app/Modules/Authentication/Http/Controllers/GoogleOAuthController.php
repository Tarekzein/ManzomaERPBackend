<?php

namespace App\Modules\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Services\GoogleOAuthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleOAuthController extends Controller
{
    public function __construct(private readonly GoogleOAuthService $google) {}

    public function loginUrl(): JsonResponse
    {
        return ApiResponse::success($this->google->authorizationUrl('login'), 'Google sign-in URL created');
    }

    public function linkUrl(Request $request): JsonResponse
    {
        return ApiResponse::success($this->google->authorizationUrl('link', $request->user()), 'Google link URL created');
    }

    public function callback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);

        return ApiResponse::success(
            $this->google->handleCallback($data['code'], $data['state'], $data['device_name'] ?? 'ManzomaERP Web'),
            'Google sign-in completed'
        );
    }

    public function unlink(Request $request): JsonResponse
    {
        return ApiResponse::success($this->google->unlink($request->user()), 'Google account disconnected');
    }
}
