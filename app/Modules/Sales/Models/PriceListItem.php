<?php

namespace App\Modules\Sales\Models;

use App\Modules\Inventory\Models\Product;
use Illuminate\Database\Eloquent\Model;

class PriceListItem extends Model
{
    protected $table = 'sales_price_list_items';

    protected $guarded = [];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
