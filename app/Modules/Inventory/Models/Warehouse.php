<?php

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;

class Warehouse extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => 'array', 'is_active' => 'boolean'];
    }

    public function locations()
    {
        return $this->hasMany(WarehouseLocation::class);
    }
}
