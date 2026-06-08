<?php

namespace App\Modules\Inventory\Services\Valuation;

use App\Modules\Inventory\Contracts\ValuationStrategy;
use InvalidArgumentException;

class ValuationStrategyFactory
{
    public function make(string $method): ValuationStrategy
    {
        return match ($method) {
            'fifo' => app(FifoValuationStrategy::class),
            'lifo' => app(LifoValuationStrategy::class),
            'average' => app(AverageCostValuationStrategy::class),
            default => throw new InvalidArgumentException("Unsupported valuation method: {$method}"),
        };
    }
}
