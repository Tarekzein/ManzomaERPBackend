<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class ExchangeRate extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['rate_date' => 'date'];
    }
}
