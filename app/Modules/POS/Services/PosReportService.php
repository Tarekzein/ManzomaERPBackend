<?php

namespace App\Modules\POS\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\POS\Models\PosSale;
use App\Modules\POS\Policies\PosPolicy;
use App\Support\Decimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * POS reporting.
 *
 * Every figure is aggregated in SQL from the immutable sale snapshots, scoped
 * to the workspace by company_id on the rows themselves. Returns are netted
 * off rather than hidden, so a day's revenue reads the same here as it does in
 * Finance.
 */
class PosReportService
{
    public function __construct(private readonly PosPolicy $policy) {}

    /** @return array{from: string, to: string, company_id: int} */
    private function scope(User $user, array $filters): array
    {
        $companyId = $this->policy->companyId($user, 'pos.reports.view');

        $businessDate = Carbon::parse($this->policy->businessDate($user));

        return [
            'company_id' => $companyId,
            'from' => $filters['from'] ?? $businessDate->copy()->startOfMonth()->toDateString(),
            'to' => $filters['to'] ?? $businessDate->toDateString(),
        ];
    }

    private function sales(array $scope, ?int $registerId = null, ?int $cashierId = null)
    {
        return DB::table('pos_sales')
            ->where('company_id', $scope['company_id'])
            ->whereBetween('business_date', [$scope['from'], $scope['to']])
            ->when($registerId, fn ($query, $id) => $query->where('pos_register_id', $id))
            ->when($cashierId, fn ($query, $id) => $query->where('cashier_id', $id));
    }

    /** Headline trading figures for the period. */
    public function summary(User $user, array $filters): array
    {
        $scope = $this->scope($user, $filters);
        $registerId = $filters['register_id'] ?? null;

        $completed = $this->sales($scope, $registerId)
            ->whereIn('status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->selectRaw('
                COUNT(*) AS sale_count,
                COALESCE(SUM(total), 0) AS gross,
                COALESCE(SUM(discount_total), 0) AS discounts
            ')
            ->first();

        $returned = $this->returnTotals($scope, $registerId);

        $gross = Decimal::of($completed->gross);
        $returnedTotal = Decimal::of($returned->returned_total);
        $net = $gross->minus($returnedTotal);
        $netTax = Decimal::of($returned->gross_tax)->minus($returned->returned_tax);
        $cost = Decimal::of($returned->gross_cost)->minus($returned->restocked_cost);
        $saleCount = (int) $completed->sale_count;

        return [
            'from' => $scope['from'],
            'to' => $scope['to'],
            'sale_count' => $saleCount,
            'gross_sales' => $gross->toString(),
            'returns' => $returnedTotal->toString(),
            'net_sales' => $net->toString(),
            'discounts' => Decimal::of($completed->discounts)->toString(),
            'tax' => $netTax->toString(),
            'cost_of_sales' => $cost->toString(),
            'gross_margin' => $net->minus($cost)->toString(),
            'margin_percent' => $net->isPositive()
                ? $net->minus($cost)->dividedBy($net)->times(100)->round()->toString()
                : '0.00',
            // What a manager actually asks first: is the basket getting bigger?
            'average_basket' => $saleCount > 0 ? $net->dividedBy($saleCount)->round()->toString() : '0.00',
            'daily' => $this->daily($scope, $registerId),
        ];
    }

    /** Cash accountability per shift, including unexplained variance. */
    public function shifts(User $user, array $filters): array
    {
        $scope = $this->scope($user, $filters);

        $rows = DB::table('pos_shifts')
            ->join('users', 'users.id', '=', 'pos_shifts.cashier_id')
            ->join('pos_registers', 'pos_registers.id', '=', 'pos_shifts.pos_register_id')
            ->where('pos_shifts.company_id', $scope['company_id'])
            ->whereBetween('pos_shifts.business_date', [$scope['from'], $scope['to']])
            ->when($filters['register_id'] ?? null, fn ($query, $id) => $query->where('pos_shifts.pos_register_id', $id))
            ->select([
                'pos_shifts.id', 'pos_shifts.business_date', 'pos_shifts.status',
                'pos_shifts.opening_float', 'pos_shifts.expected_cash', 'pos_shifts.counted_cash',
                'pos_shifts.cash_variance', 'pos_shifts.sales_total', 'pos_shifts.returns_total', 'pos_shifts.sale_count',
                'pos_shifts.opened_at', 'pos_shifts.closed_at', 'pos_shifts.variance_approved_by',
                'users.name AS cashier', 'pos_registers.name AS register',
            ])
            ->orderByDesc('pos_shifts.business_date')
            ->get();

        return [
            'shifts' => $rows,
            'total_variance' => Decimal::sum($rows->pluck('cash_variance')->filter())->toString(),
            'unexplained_count' => $rows
                ->filter(fn ($row) => $row->cash_variance !== null
                    && ! Decimal::of($row->cash_variance)->isZero()
                    && $row->variance_approved_by === null)
                ->count(),
        ];
    }

    /** Takings split by tender, which is what reconciles against the bank. */
    public function tenders(User $user, array $filters): array
    {
        $scope = $this->scope($user, $filters);
        $registerId = $filters['register_id'] ?? null;

        $captured = DB::table('pos_tenders')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_tenders.pos_sale_id')
            ->where('pos_tenders.company_id', $scope['company_id'])
            ->whereBetween('pos_sales.business_date', [$scope['from'], $scope['to']])
            ->whereIn('pos_sales.status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->where('pos_tenders.status', 'captured')
            ->when($registerId, fn ($query, $id) => $query->where('pos_sales.pos_register_id', $id))
            ->selectRaw('
                pos_tenders.tender_type AS name,
                COUNT(*) AS count,
                COALESCE(SUM(pos_tenders.amount), 0) AS captured
            ')
            ->groupBy('pos_tenders.tender_type')
            ->get()
            ->keyBy('name');

        $refunded = DB::table('pos_refunds')
            ->join('pos_returns', 'pos_returns.id', '=', 'pos_refunds.pos_return_id')
            ->where('pos_refunds.company_id', $scope['company_id'])
            ->whereBetween('pos_returns.business_date', [$scope['from'], $scope['to']])
            ->where('pos_returns.status', 'completed')
            ->where('pos_refunds.status', 'completed')
            ->when($registerId, fn ($query, $id) => $query->where('pos_returns.pos_register_id', $id))
            ->selectRaw('
                pos_refunds.tender_type AS name,
                COALESCE(SUM(pos_refunds.amount), 0) AS refunded
            ')
            ->groupBy('pos_refunds.tender_type')
            ->get()
            ->keyBy('name');

        $rows = $captured->keys()->merge($refunded->keys())->unique()->map(function ($name) use ($captured, $refunded) {
            $capture = $captured->get($name);
            $refund = $refunded->get($name);
            $capturedAmount = Decimal::of($capture->captured ?? 0);
            $refundedAmount = Decimal::of($refund->refunded ?? 0);

            return (object) [
                'name' => $name,
                'count' => (int) ($capture->count ?? 0),
                'captured' => $capturedAmount->toString(),
                'refunded' => $refundedAmount->toString(),
                'value' => $capturedAmount->minus($refundedAmount)->toString(),
            ];
        })->sortByDesc(fn ($row) => Decimal::of($row->value)->toFloat())->values();

        return ['tenders' => $rows, 'total' => Decimal::sum($rows->pluck('value'))->toString()];
    }

    /** What actually sells, ranked. */
    public function products(User $user, array $filters): array
    {
        $scope = $this->scope($user, $filters);
        $registerId = $filters['register_id'] ?? null;

        $grossRows = DB::table('pos_sale_lines')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.pos_sale_id')
            ->where('pos_sale_lines.company_id', $scope['company_id'])
            ->whereBetween('pos_sales.business_date', [$scope['from'], $scope['to']])
            ->whereIn('pos_sales.status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->when($registerId, fn ($query, $id) => $query->where('pos_sales.pos_register_id', $id))
            ->selectRaw('
                pos_sale_lines.product_id,
                pos_sale_lines.product_name AS name,
                pos_sale_lines.sku,
                COALESCE(SUM(pos_sale_lines.quantity), 0) AS quantity,
                COALESCE(SUM(pos_sale_lines.line_total), 0) AS value,
                COALESCE(SUM(pos_sale_lines.cost_total), 0) AS cost
            ')
            ->groupBy('pos_sale_lines.product_id', 'pos_sale_lines.product_name', 'pos_sale_lines.sku')
            ->get()
            ->keyBy('product_id');

        $returnedRows = DB::table('pos_return_lines')
            ->join('pos_returns', 'pos_returns.id', '=', 'pos_return_lines.pos_return_id')
            ->join('pos_sale_lines', 'pos_sale_lines.id', '=', 'pos_return_lines.pos_sale_line_id')
            ->where('pos_return_lines.company_id', $scope['company_id'])
            ->whereBetween('pos_returns.business_date', [$scope['from'], $scope['to']])
            ->where('pos_returns.status', 'completed')
            ->when($registerId, fn ($query, $id) => $query->where('pos_returns.pos_register_id', $id))
            ->selectRaw('
                pos_return_lines.product_id,
                MAX(pos_sale_lines.product_name) AS name,
                MAX(pos_sale_lines.sku) AS sku,
                COALESCE(SUM(pos_return_lines.quantity), 0) AS quantity,
                COALESCE(SUM(pos_return_lines.line_total), 0) AS value,
                COALESCE(SUM(CASE WHEN pos_return_lines.disposition = ? THEN pos_return_lines.cost_total ELSE 0 END), 0) AS restocked_cost
            ', ['restock'])
            ->groupBy('pos_return_lines.product_id')
            ->get()
            ->keyBy('product_id');

        $rows = $grossRows->keys()->merge($returnedRows->keys())->unique()->map(function ($productId) use ($grossRows, $returnedRows) {
            $gross = $grossRows->get($productId);
            $returned = $returnedRows->get($productId);
            $returnedQuantity = Decimal::of($returned->quantity ?? 0);
            $value = Decimal::of($gross->value ?? 0)->minus($returned->value ?? 0);
            $cost = Decimal::of($gross->cost ?? 0)->minus($returned->restocked_cost ?? 0);

            return (object) [
                'product_id' => (int) $productId,
                'name' => $gross->name ?? $returned->name,
                'sku' => $gross->sku ?? $returned->sku,
                'quantity' => Decimal::of($gross->quantity ?? 0)->minus($returnedQuantity)->toString(),
                'returned_quantity' => $returnedQuantity->toString(),
                'value' => $value->toString(),
                'cost' => $cost->toString(),
                'margin' => $value->minus($cost)->toString(),
            ];
        })->sortByDesc(fn ($row) => Decimal::of($row->value)->toFloat())
            ->take((int) ($filters['limit'] ?? 50))
            ->values();

        return ['products' => $rows];
    }

    /** Tax collected per rate, for the return. */
    public function taxes(User $user, array $filters): array
    {
        $scope = $this->scope($user, $filters);
        $registerId = $filters['register_id'] ?? null;

        $gross = DB::table('pos_sale_lines')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.pos_sale_id')
            ->where('pos_sale_lines.company_id', $scope['company_id'])
            ->whereBetween('pos_sales.business_date', [$scope['from'], $scope['to']])
            ->whereIn('pos_sales.status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->when($registerId, fn ($query, $id) => $query->where('pos_sales.pos_register_id', $id))
            ->selectRaw('
                pos_sale_lines.tax_rate AS name,
                COALESCE(SUM(pos_sale_lines.line_subtotal), 0) AS taxable,
                COALESCE(SUM(pos_sale_lines.tax_amount), 0) AS value
            ')
            ->groupBy('pos_sale_lines.tax_rate')
            ->get()
            ->keyBy('name');

        $returned = DB::table('pos_return_lines')
            ->join('pos_returns', 'pos_returns.id', '=', 'pos_return_lines.pos_return_id')
            ->join('pos_sale_lines', 'pos_sale_lines.id', '=', 'pos_return_lines.pos_sale_line_id')
            ->where('pos_return_lines.company_id', $scope['company_id'])
            ->whereBetween('pos_returns.business_date', [$scope['from'], $scope['to']])
            ->where('pos_returns.status', 'completed')
            ->when($registerId, fn ($query, $id) => $query->where('pos_returns.pos_register_id', $id))
            ->selectRaw('
                pos_sale_lines.tax_rate AS name,
                COALESCE(SUM(pos_return_lines.line_total - pos_return_lines.tax_amount), 0) AS taxable,
                COALESCE(SUM(pos_return_lines.tax_amount), 0) AS value
            ')
            ->groupBy('pos_sale_lines.tax_rate')
            ->get()
            ->keyBy('name');

        $rows = $gross->keys()->merge($returned->keys())->unique()->map(function ($name) use ($gross, $returned) {
            $sale = $gross->get($name);
            $reversal = $returned->get($name);

            return (object) [
                'name' => $name,
                'taxable' => Decimal::of($sale->taxable ?? 0)->minus($reversal->taxable ?? 0)->toString(),
                'value' => Decimal::of($sale->value ?? 0)->minus($reversal->value ?? 0)->toString(),
            ];
        })->sortByDesc(fn ($row) => Decimal::of($row->value)->toFloat())->values();

        return ['rates' => $rows, 'total' => Decimal::sum($rows->pluck('value'))->toString()];
    }

    /** Margin by cashier and register — the reason cost is snapshotted. */
    public function margins(User $user, array $filters): array
    {
        $scope = $this->scope($user, $filters);
        $registerId = $filters['register_id'] ?? null;

        $byCashier = DB::table('pos_sales')
            ->join('users', 'users.id', '=', 'pos_sales.cashier_id')
            ->where('pos_sales.company_id', $scope['company_id'])
            ->whereBetween('pos_sales.business_date', [$scope['from'], $scope['to']])
            ->whereIn('pos_sales.status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->when($registerId, fn ($query, $id) => $query->where('pos_sales.pos_register_id', $id))
            ->selectRaw('
                users.id AS cashier_id,
                users.name AS name,
                COUNT(*) AS sales,
                COALESCE(SUM(pos_sales.total), 0) AS value,
                COALESCE(SUM(pos_sales.cost_total), 0) AS cost,
                COALESCE(SUM(pos_sales.discount_total), 0) AS discounts
            ')
            ->groupBy('users.id', 'users.name')
            ->get();

        $returned = DB::table('pos_returns')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_returns.pos_sale_id')
            ->join('users', 'users.id', '=', 'pos_sales.cashier_id')
            ->leftJoinSub($this->returnCostsByReturn(), 'return_costs', fn ($join) => $join
                ->on('return_costs.pos_return_id', '=', 'pos_returns.id'))
            ->where('pos_returns.company_id', $scope['company_id'])
            ->whereBetween('pos_returns.business_date', [$scope['from'], $scope['to']])
            ->where('pos_returns.status', 'completed')
            ->when($registerId, fn ($query, $id) => $query->where('pos_returns.pos_register_id', $id))
            ->selectRaw('
                pos_sales.cashier_id,
                MAX(users.name) AS name,
                COALESCE(SUM(pos_returns.total), 0) AS total,
                COALESCE(SUM(return_costs.restocked_cost), 0) AS restocked_cost
            ')
            ->groupBy('pos_sales.cashier_id')
            ->get()
            ->keyBy('cashier_id');

        $byCashier = $byCashier->keyBy('cashier_id');
        $byCashier = $byCashier->keys()->merge($returned->keys())->unique()->map(function ($cashierId) use ($byCashier, $returned) {
            $sales = $byCashier->get($cashierId);
            $reversal = $returned->get($cashierId);
            $value = Decimal::of($sales->value ?? 0)->minus($reversal->total ?? 0);
            $cost = Decimal::of($sales->cost ?? 0)->minus($reversal->restocked_cost ?? 0);

            return (object) [
                'cashier_id' => (int) $cashierId,
                'name' => $sales->name ?? $reversal->name,
                'sales' => (int) ($sales->sales ?? 0),
                'value' => $value->toString(),
                'cost' => $cost->toString(),
                'discounts' => Decimal::of($sales->discounts ?? 0)->toString(),
                'margin' => $value->minus($cost)->toString(),
            ];
        })->sortByDesc(fn ($row) => Decimal::of($row->value)->toFloat())->values();

        return ['by_cashier' => $byCashier];
    }

    /** Gross sale and reversing return facts for headline reporting. */
    private function returnTotals(array $scope, ?int $registerId = null): object
    {
        // Returns are attributed to their own processing date. The original
        // sale may be outside the requested period, but its reversal still
        // belongs in the selected day's POS/Finance reconciliation.
        $returns = DB::table('pos_returns')
            ->leftJoin('pos_return_lines', 'pos_return_lines.pos_return_id', '=', 'pos_returns.id')
            ->where('pos_returns.company_id', $scope['company_id'])
            ->whereBetween('pos_returns.business_date', [$scope['from'], $scope['to']])
            ->where('pos_returns.status', 'completed')
            ->when($registerId, fn ($query, $id) => $query->where('pos_returns.pos_register_id', $id))
            ->selectRaw('
                COALESCE(SUM(pos_return_lines.line_total), 0) AS returned_total,
                COALESCE(SUM(pos_return_lines.tax_amount), 0) AS returned_tax,
                COALESCE(SUM(CASE WHEN pos_return_lines.disposition = ? THEN pos_return_lines.cost_total ELSE 0 END), 0) AS restocked_cost
            ', ['restock'])
            ->first();

        $sales = DB::table('pos_sale_lines')
            ->join('pos_sales', 'pos_sales.id', '=', 'pos_sale_lines.pos_sale_id')
            ->where('pos_sales.company_id', $scope['company_id'])
            ->whereBetween('pos_sales.business_date', [$scope['from'], $scope['to']])
            ->whereIn('pos_sales.status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->when($registerId, fn ($query, $id) => $query->where('pos_sales.pos_register_id', $id))
            ->selectRaw('
                COALESCE(SUM(pos_sale_lines.tax_amount), 0) AS gross_tax,
                COALESCE(SUM(pos_sale_lines.cost_total), 0) AS gross_cost
            ')
            ->first();

        return (object) [
            'gross_tax' => $sales->gross_tax,
            'gross_cost' => $sales->gross_cost,
            'returned_total' => $returns->returned_total,
            'returned_tax' => $returns->returned_tax,
            'restocked_cost' => $returns->restocked_cost,
        ];
    }

    /** Net trading value by the date on which each sale or return occurred. */
    private function daily(array $scope, ?int $registerId = null)
    {
        $sales = $this->sales($scope, $registerId)
            ->whereIn('status', [PosSale::STATUS_COMPLETED, PosSale::STATUS_VOIDED])
            ->selectRaw('business_date AS period, COALESCE(SUM(total), 0) AS value, COUNT(*) AS sales')
            ->groupBy('business_date')
            ->get()
            ->keyBy('period');

        $returns = DB::table('pos_returns')
            ->where('company_id', $scope['company_id'])
            ->whereBetween('business_date', [$scope['from'], $scope['to']])
            ->where('status', 'completed')
            ->when($registerId, fn ($query, $id) => $query->where('pos_register_id', $id))
            ->selectRaw('business_date AS period, COALESCE(SUM(total), 0) AS value')
            ->groupBy('business_date')
            ->get()
            ->keyBy('period');

        return $sales->keys()->merge($returns->keys())->unique()->map(function ($period) use ($sales, $returns) {
            $sale = $sales->get($period);
            $return = $returns->get($period);

            return (object) [
                'period' => $period,
                'value' => Decimal::of($sale->value ?? 0)->minus($return->value ?? 0)->toString(),
                'sales' => (int) ($sale->sales ?? 0),
            ];
        })->sortBy('period')->values();
    }

    /** Restock cost grouped once per return, avoiding return-header duplication. */
    private function returnCostsByReturn()
    {
        return DB::table('pos_return_lines')
            ->selectRaw('
                pos_return_id,
                COALESCE(SUM(CASE WHEN disposition = ? THEN cost_total ELSE 0 END), 0) AS restocked_cost
            ', ['restock'])
            ->groupBy('pos_return_id');
    }
}
