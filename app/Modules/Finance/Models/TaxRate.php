<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
