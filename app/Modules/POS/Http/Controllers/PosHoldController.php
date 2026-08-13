<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosHold;
use App\Modules\POS\Services\PosHoldService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosHoldController extends Controller
{
    public function __construct(private readonly PosHoldService $holds) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['pos_register_id' => ['required', 'integer']]);

        return ApiResponse::success(
            $this->holds->list($request->user(), (int) $data['pos_register_id']),
            'Held carts loaded',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pos_register_id' => ['required', 'integer'],
            'sales_contact_id' => ['sometimes', 'nullable', 'integer'],
            'label' => ['sometimes', 'nullable', 'string', 'max:120'],
            'expires_in_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.discount_percent' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        return ApiResponse::success($this->holds->store($request->user(), $data), 'Cart held', status: 201);
    }

    public function destroy(Request $request, PosHold $hold): JsonResponse
    {
        $this->holds->destroy($request->user(), $hold);

        return ApiResponse::success(null, 'Held cart removed');
    }
}
