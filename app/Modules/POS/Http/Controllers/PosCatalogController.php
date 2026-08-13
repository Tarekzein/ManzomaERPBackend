<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\POS\Services\PosCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosCatalogController extends Controller
{
    public function __construct(
        private readonly PosCatalogService $catalog,
        private readonly PosPolicy $policy,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        return ApiResponse::success($this->catalog->bootstrap($request->user()), 'POS bootstrap loaded');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'pos_register_id' => ['required', 'integer'],
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $register = $this->policy->register($request->user(), (int) $filters['pos_register_id']);
        $this->policy->ensureAssigned($request->user(), $register);

        return ApiResponse::success(
            $this->catalog->search($request->user(), $register, $filters),
            'Catalog loaded',
        );
    }

    public function barcode(Request $request, string $barcode): JsonResponse
    {
        $data = $request->validate(['pos_register_id' => ['required', 'integer']]);
        $register = $this->policy->register($request->user(), (int) $data['pos_register_id']);
        $this->policy->ensureAssigned($request->user(), $register);
        $product = $this->catalog->findByBarcode($request->user(), $register, $barcode);

        if (! $product) {
            return ApiResponse::error('No product matches that barcode.', status: 404);
        }

        return ApiResponse::success($product, 'Product found');
    }
}
