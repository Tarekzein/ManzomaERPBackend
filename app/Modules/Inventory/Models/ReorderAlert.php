<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class ReorderAlert extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    public function balance()
    {
        return $this->belongsTo(StockBalance::class, 'stock_balance_id');
    }
}
