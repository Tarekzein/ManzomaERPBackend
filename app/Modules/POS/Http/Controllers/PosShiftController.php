<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosCashMovement;
use App\Modules\POS\Models\PosShift;
use App\Modules\POS\Services\PosShiftService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosShiftController extends Controller
{
    public function __construct(private readonly PosShiftService $shifts) {}

    public function current(Request $request): JsonResponse
    {
        return ApiResponse::success($this->shifts->current($request->user()), 'Current shift loaded');
    }

    public function open(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pos_register_id' => ['required', 'integer'],
            'opening_float' => ['sometimes', 'numeric', 'min:0'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        $outcome = $this->shifts->open($request->user(), $data);

        return ApiResponse::success(
            $outcome['shift'],
            $outcome['replayed'] ? 'Shift already open' : 'Shift opened',
            status: $outcome['replayed'] ? 200 : 201,
        );
    }

    public function cashMovement(Request $request, PosShift $shift): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'type' => ['required', Rule::in(PosCashMovement::TYPES)],
            'amount' => ['sometimes', 'numeric', 'min:0'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'supervisor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $outcome = $this->shifts->recordCashMovement($request->user(), $shift, $data);

        return ApiResponse::success(
            $outcome['movement'],
            $outcome['replayed'] ? 'Cash movement already recorded' : 'Cash movement recorded',
            status: $outcome['replayed'] ? 200 : 201,
        );
    }

    public function close(Request $request, PosShift $shift): JsonResponse
    {
        $data = $request->validate([
            'counted_cash' => ['sometimes', 'numeric', 'min:0'],
            'denominations' => ['sometimes', 'array'],
            'denominations.*.value' => ['required', 'numeric', 'min:0'],
            'denominations.*.count' => ['required', 'integer', 'min:0'],
            'supervisor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ]);

        $outcome = $this->shifts->close($request->user(), $shift, $data);

        return ApiResponse::success($outcome['shift'], 'Shift closed');
    }
}
