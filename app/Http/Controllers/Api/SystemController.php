<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\SystemHealthService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        return ApiResponse::success([
            'service' => config('app.name'),
            'version' => config('erp.version'),
            'environment' => app()->environment(),
            'timestamp' => now()->toISOString(),
            'status' => 'ok',
        ], 'ERP API is healthy');
    }

    public function detailedHealth(Request $request, SystemHealthService $health): JsonResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        return ApiResponse::success($health->report(), 'System health checked');
    }

    public function modules(): JsonResponse
    {
        return ApiResponse::success(config('erp.modules'), 'ERP modules loaded');
    }
}
