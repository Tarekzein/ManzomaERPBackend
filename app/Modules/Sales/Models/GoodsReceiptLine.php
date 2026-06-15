<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

class GoodsReceiptLine extends Model
{
    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
