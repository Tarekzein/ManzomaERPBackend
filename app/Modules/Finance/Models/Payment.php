<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['payment_date' => 'date'];
    }
}
