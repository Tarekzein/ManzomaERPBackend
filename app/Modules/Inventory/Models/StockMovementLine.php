<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovementLine extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
