<?php

namespace App\Modules\CustomModules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\CustomModules\Models\CustomModule;
use App\Modules\CustomModules\Services\CustomModuleService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomModuleController extends Controller
{
    public function __construct(private readonly CustomModuleService $modules) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->modules->catalog($request->user()), 'Module catalog loaded');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success($this->modules->save($request->user(), $this->validateModule($request)), 'Module created', status: 201);
    }

    public function update(Request $request, CustomModule $module): JsonResponse
    {
        return ApiResponse::success($this->modules->save($request->user(), $this->validateModule($request, $module), $module), 'Module updated');
    }

    public function install(Request $request, CustomModule $module): JsonResponse
    {
        $data = $request->validate(['settings' => ['sometimes', 'array']]);

        return ApiResponse::success($this->modules->install($request->user(), $module, $data['settings'] ?? []), 'Module installed');
    }

    public function status(Request $request, CustomModule $module): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['enabled', 'disabled'])]]);

        return ApiResponse::success($this->modules->setStatus($request->user(), $module, $data['status']), 'Module status updated');
    }

    public function uninstall(Request $request, CustomModule $module): JsonResponse
    {
        $this->modules->uninstall($request->user(), $module);

        return ApiResponse::success(null, 'Module uninstalled');
    }

    private function validateModule(Request $request, ?CustomModule $module = null): array
    {
        return $request->validate([
            'slug' => ['required', 'string', 'max:120', Rule::unique('custom_modules')->ignore($module)],
            'name' => ['required', 'string', 'max:150'],
            'version' => ['required', 'regex:/^\d+\.\d+\.\d+$/'],
            'description' => ['nullable', 'string'],
            'publisher' => ['nullable', 'string', 'max:150'],
            'minimum_erp_version' => ['nullable', 'regex:/^\d+\.\d+\.\d+$/'],
            'manifest' => ['required', 'array'],
            'manifest.permissions' => ['sometimes', 'array'],
            'manifest.permissions.*' => ['string'],
            'status' => ['sometimes', Rule::in(['draft', 'approved', 'rejected'])],
            'is_active' => ['sometimes', 'boolean'],
        ]);
    }
}
