<?php

namespace App\Modules\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class PriceList extends Model
{
    protected $table = 'sales_price_lists';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function contact()
    {
        return $this->belongsTo(SalesContact::class);
    }

    public function items()
    {
        return $this->hasMany(PriceListItem::class);
    }
}
