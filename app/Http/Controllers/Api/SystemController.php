<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class SystemController extends Controller
{
    public function health(): JsonResponse
    {
        return ApiResponse::success([
            'service' => config('app.name'),
            'version' => config('erp.version'),
            'environment' => app()->environment(),
        ], 'ERP API is healthy');
    }

    public function modules(): JsonResponse
    {
        return ApiResponse::success(config('erp.modules'), 'ERP modules loaded');
    }
}
