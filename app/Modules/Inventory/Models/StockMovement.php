<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class StockMovement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function lines()
    {
        return $this->hasMany(StockMovementLine::class);
    }
}
