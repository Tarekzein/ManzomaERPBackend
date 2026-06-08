<?php

namespace App\Modules\Inventory\Contracts;

use App\Modules\Inventory\Models\StockBalance;

interface ValuationStrategy
{
    public function issue(StockBalance $balance, float $quantity): float;
}
