<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class FinanceContact extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => 'array'];
    }
}
