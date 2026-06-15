<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

class SalesOrderLine extends Model
{
    protected $guarded = [];

    public function document()
    {
        return $this->morphTo();
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
