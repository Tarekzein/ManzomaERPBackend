<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosReturn;
use App\Modules\POS\Models\PosReturnLine;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\POS\Services\PosReturnService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosReturnController extends Controller
{
    public function __construct(
        private readonly PosReturnService $returns,
        private readonly PosPolicy $policy,
    ) {}

    public function store(Request $request, PosSale $sale): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'pos_register_id' => ['sometimes', 'integer'],
            'supervisor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.pos_sale_line_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.disposition' => ['sometimes', Rule::in(PosReturnLine::DISPOSITIONS)],
            'refunds' => ['sometimes', 'array'],
            'refunds.*.pos_tender_id' => ['required', 'integer'],
            'refunds.*.amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $outcome = $this->returns->returnSale($request->user(), $sale, $data);

        return ApiResponse::success(
            $outcome['return'],
            $outcome['replayed'] ? 'Return already recorded' : 'Return completed',
            status: $outcome['replayed'] ? 200 : 201,
        );
    }

    public function void(Request $request, PosSale $sale): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:128'],
            'supervisor_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $outcome = $this->returns->void($request->user(), $sale, $data);

        return ApiResponse::success(
            $outcome['return'],
            $outcome['replayed'] ? 'Sale already voided' : 'Sale voided',
            status: $outcome['replayed'] ? 200 : 201,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'pos.view');

        $returns = PosReturn::query()
            ->where('company_id', $companyId)
            ->when($request->query('from'), fn ($query, $value) => $query->whereDate('business_date', '>=', $value))
            ->when($request->query('to'), fn ($query, $value) => $query->whereDate('business_date', '<=', $value))
            ->with(['lines', 'refunds', 'sale:id,receipt_number'])
            ->latest()
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ApiResponse::success($returns, 'Returns loaded');
    }
}
