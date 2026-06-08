<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\ReorderAlert;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Policies\InventoryPolicy;

class InventoryReportService
{
    public function __construct(private readonly InventoryPolicy $policy) {}

    public function onHand(User $user)
    {
        return StockBalance::with('product.unit', 'warehouse', 'location')->where('company_id', $this->policy->companyId($user))->get();
    }

    public function movements(User $user)
    {
        return StockMovement::with('lines.product')->where('company_id', $this->policy->companyId($user))->latest('occurred_at')->get();
    }

    public function valuation(User $user): array
    {
        $balances = $this->onHand($user);

        $items = $balances->map(function (StockBalance $balance) {
            $value = $balance->product->valuation_method === 'average'
                ? (float) $balance->quantity * (float) $balance->average_cost
                : (float) InventoryCostLayer::where('product_id', $balance->product_id)->where('warehouse_id', $balance->warehouse_id)
                    ->where('location_id', $balance->location_id)->selectRaw('SUM(remaining_quantity * unit_cost) value')->value('value');

            return [
                'product' => $balance->product, 'warehouse' => $balance->warehouse, 'location' => $balance->location,
                'quantity' => (float) $balance->quantity, 'unit_cost' => (float) $balance->average_cost, 'value' => round($value, 4),
            ];
        });

        return ['items' => $items, 'total_value' => round($items->sum('value'), 4)];
    }

    public function alerts(User $user)
    {
        return ReorderAlert::with('balance.product', 'balance.warehouse')->where('company_id', $this->policy->companyId($user))->where('status', 'open')->get();
    }

    public function setReorder(User $user, StockBalance $balance, array $data): StockBalance
    {
        $this->policy->ensureOwned($user, $balance);
        $balance->update($data);
        app(ReorderAlertService::class)->evaluate($balance->refresh());

        return $balance->load('product', 'warehouse', 'location');
    }
}
