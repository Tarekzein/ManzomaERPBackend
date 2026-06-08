<?php

namespace App\Modules\Inventory\Services\Valuation;

use App\Modules\Inventory\Contracts\ValuationStrategy;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\StockBalance;
use Illuminate\Validation\ValidationException;

class FifoValuationStrategy implements ValuationStrategy
{
    public function issue(StockBalance $balance, float $quantity): float
    {
        return $this->consume($balance, $quantity, 'asc');
    }

    protected function consume(StockBalance $balance, float $quantity, string $direction): float
    {
        $remaining = $quantity;
        $cost = 0.0;
        $layers = InventoryCostLayer::where('product_id', $balance->product_id)
            ->where('warehouse_id', $balance->warehouse_id)->where('location_id', $balance->location_id)
            ->where('remaining_quantity', '>', 0)->orderBy('id', $direction)->lockForUpdate()->get();
        foreach ($layers as $layer) {
            $used = min($remaining, (float) $layer->remaining_quantity);
            $layer->decrement('remaining_quantity', $used);
            $cost += $used * (float) $layer->unit_cost;
            $remaining -= $used;
            if ($remaining <= 0.0001) {
                break;
            }
        }
        if ($remaining > 0.0001) {
            throw ValidationException::withMessages(['quantity' => ['Insufficient valuation layers for this stock issue.']]);
        }

        return round($cost / $quantity, 4);
    }
}
