<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location()
    {
        return $this->belongsTo(WarehouseLocation::class);
    }
}
