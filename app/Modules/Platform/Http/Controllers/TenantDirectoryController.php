<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\TenantDirectoryService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantDirectoryController extends Controller
{
    public function __construct(private readonly TenantDirectoryService $tenants) {}

    public function __invoke(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:150'],
            'status' => ['sometimes', 'nullable', 'string', 'max:20'],
            'plan' => ['sometimes', 'nullable', 'string', 'max:60'],
            'include_archived' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::success(
            $this->tenants->directory($request->user(), $filters),
            'Tenant directory loaded',
        );
    }
}
