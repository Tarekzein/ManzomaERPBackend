<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosRegister;
use App\Modules\POS\Models\PosRegisterAssignment;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Services\PosRegisterService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosRegisterController extends Controller
{
    public function __construct(private readonly PosRegisterService $registers) {}

    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->registers->list($request->user()), 'Registers loaded');
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->registers->create($request->user(), $this->validated($request)),
            'Register created',
            status: 201,
        );
    }

    public function update(Request $request, PosRegister $register): JsonResponse
    {
        return ApiResponse::success(
            $this->registers->update($request->user(), $register, $this->validated($request, true)),
            'Register updated',
        );
    }

    public function assign(Request $request, PosRegister $register): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'role' => ['sometimes', Rule::in([PosRegisterAssignment::ROLE_CASHIER, PosRegisterAssignment::ROLE_SUPERVISOR])],
            'starts_on' => ['sometimes', 'nullable', 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        return ApiResponse::success(
            $this->registers->assign($request->user(), $register, $data),
            'Register assignment saved',
            status: 201,
        );
    }

    public function unassign(Request $request, PosRegister $register, int $assignment): JsonResponse
    {
        $this->registers->unassign($request->user(), $register, $assignment);

        return ApiResponse::success(null, 'Register assignment removed');
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'warehouse_id' => [$required, 'integer', 'exists:warehouses,id'],
            'location_id' => ['sometimes', 'nullable', 'integer', 'exists:warehouse_locations,id'],
            'code' => [$required, 'string', 'max:32'],
            'name' => [$required, 'string', 'max:255'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'receipt_prefix' => ['sometimes', 'string', 'max:12'],
            'is_active' => ['sometimes', 'boolean'],
            'settings' => ['sometimes', 'array'],
            'payment_methods' => ['sometimes', 'array'],
            'payment_methods.*.tender_type' => ['required', Rule::in(PosRegisterPaymentMethod::CHECKOUT_TYPES)],
            'payment_methods.*.label' => ['sometimes', 'string', 'max:255'],
            'payment_methods.*.provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'payment_methods.*.account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'payment_methods.*.clearing_account_id' => ['sometimes', 'nullable', 'integer', 'exists:accounts,id'],
            'payment_methods.*.is_active' => ['sometimes', 'boolean'],
            'payment_methods.*.opens_drawer' => ['sometimes', 'boolean'],
            'payment_methods.*.sort_order' => ['sometimes', 'integer'],
            'payment_methods.*.settings' => ['sometimes', 'nullable', 'array'],
        ]);
    }
}
