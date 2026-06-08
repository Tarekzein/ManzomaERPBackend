<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;

class FinancialPeriod extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'is_locked' => 'boolean', 'locked_at' => 'datetime'];
    }
}
