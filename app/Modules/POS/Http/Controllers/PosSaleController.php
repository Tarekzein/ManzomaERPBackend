<?php

namespace App\Modules\POS\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\POS\Models\PosRegisterPaymentMethod;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Policies\PosPolicy;
use App\Modules\POS\Services\PosCheckoutService;
use App\Modules\POS\Services\PosPricingService;
use App\Modules\POS\Services\PosReceiptService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PosSaleController extends Controller
{
    public function __construct(
        private readonly PosCheckoutService $checkout,
        private readonly PosPricingService $pricing,
        private readonly PosPolicy $policy,
        private readonly PosReceiptService $receipts,
    ) {}

    /** Preview a cart. Same engine as checkout, so the totals agree. */
    public function price(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pos_register_id' => ['required', 'integer'],
            'sales_contact_id' => ['sometimes', 'nullable', 'integer'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'lines.*.discount_amount' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $register = $this->policy->register($request->user(), (int) $data['pos_register_id']);
        $this->policy->ensureAssigned($request->user(), $register);

        return ApiResponse::success(
            $this->pricing->price($request->user(), $register, $data['lines'], $data['sales_contact_id'] ?? null),
            'Cart priced',
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuid' => ['sometimes', 'uuid'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'pos_register_id' => ['required', 'integer'],
            'sales_contact_id' => ['sometimes', 'nullable', 'integer'],
            'crm_contact_id' => ['sometimes', 'nullable', 'integer'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.unit_price' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.discount_percent' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'lines.*.discount_amount' => ['sometimes', 'numeric', 'min:0'],
            'tenders' => ['required', 'array', 'min:1'],
            'tenders.*.tender_type' => ['required', Rule::in(PosRegisterPaymentMethod::CHECKOUT_TYPES)],
            'tenders.*.amount' => ['required', 'numeric', 'gt:0'],
            'tenders.*.tendered_amount' => ['sometimes', 'numeric', 'min:0'],
            'tenders.*.provider' => ['sometimes', 'nullable', 'string', 'max:40'],
            'tenders.*.reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            // Deliberately no PAN or CVV field exists.
            'tenders.*.card_last4' => ['sometimes', 'nullable', 'string', 'max:4'],
            'tenders.*.card_brand' => ['sometimes', 'nullable', 'string', 'max:24'],
        ]);

        $outcome = $this->checkout->checkout($request->user(), $data);

        return ApiResponse::success(
            $outcome['sale'],
            $outcome['replayed'] ? 'Sale already recorded' : 'Sale completed',
            status: $outcome['replayed'] ? 200 : 201,
        );
    }

    public function index(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'pos.view');

        $sales = PosSale::query()
            ->where('company_id', $companyId)
            ->when($request->query('receipt'), fn ($query, $value) => $query->where('receipt_number', 'like', "%{$value}%"))
            ->when($request->query('cashier_id'), fn ($query, $value) => $query->where('cashier_id', $value))
            ->when($request->query('register_id'), fn ($query, $value) => $query->where('pos_register_id', $value))
            ->when($request->query('from'), fn ($query, $value) => $query->whereDate('business_date', '>=', $value))
            ->when($request->query('to'), fn ($query, $value) => $query->whereDate('business_date', '<=', $value))
            ->with(['cashier:id,name', 'register:id,name'])
            ->latest('completed_at')
            ->paginate(min((int) $request->query('per_page', 25), 100));

        return ApiResponse::success($sales, 'Sales loaded');
    }

    /** The printable receipt, built from the sale's own snapshots. */
    public function receipt(Request $request, PosSale $sale): JsonResponse
    {
        return ApiResponse::success($this->receipts->build($request->user(), $sale), 'Receipt loaded');
    }

    public function show(Request $request, PosSale $sale): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'pos.view');
        abort_unless((int) $sale->company_id === $companyId, 404);

        return ApiResponse::success(
            $sale->load(['lines', 'tenders', 'register:id,name', 'cashier:id,name', 'invoice:id,number,status', 'stockMovement:id,number']),
            'Sale loaded',
        );
    }
}
