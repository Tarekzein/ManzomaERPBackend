<?php

namespace App\Modules\Inventory\Services\Valuation;

use App\Modules\Inventory\Contracts\ValuationStrategy;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Validation\ValidationException;

class AverageCostValuationStrategy implements ValuationStrategy
{
    public function issue(StockBalance $balance, float $quantity): float
    {
        $remaining = $quantity;
        $layers = InventoryCostLayer::where('product_id', $balance->product_id)
            ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)
            ->where('remaining_quantity', '>', 0)->lockForUpdate()->get();
        foreach ($layers as $layer) {
            $used = min($remaining, (float) $layer->remaining_quantity);
            $layer->decrement('remaining_quantity', $used);
            $remaining -= $used;
            if ($remaining <= 0.0001) {
                break;
            }
        }
        if ($remaining > 0.0001) {
            throw ValidationException::withMessages(['quantity' => ['Insufficient valuation layers for this stock issue.']]);
        }

        return (float) $balance->average_cost;
    }
}
