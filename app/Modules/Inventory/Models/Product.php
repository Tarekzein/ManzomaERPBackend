<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function category()
    {
        return $this->belongsTo(ProductCategory::class);
    }

    public function unit()
    {
        return $this->belongsTo(Unit::class);
    }

    public function balances()
    {
        return $this->hasMany(StockBalance::class);
    }
}
