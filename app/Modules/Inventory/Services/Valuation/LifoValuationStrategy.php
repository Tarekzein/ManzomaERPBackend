<?php

namespace App\Modules\Inventory\Services\Valuation;

use App\Modules\Inventory\Models\StockBalance;

class LifoValuationStrategy extends FifoValuationStrategy
{
    public function issue(StockBalance $balance, float $quantity): float
    {
        return $this->consume($balance, $quantity, 'desc');
    }
}
