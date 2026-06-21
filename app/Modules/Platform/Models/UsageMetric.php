<?php

namespace App\Modules\Platform\Models;

use Illuminate\Database\Eloquent\Model;

class UsageMetric extends Model
{
    protected $fillable = ['company_id', 'metric', 'period_date', 'quantity', 'metadata'];

    protected function casts(): array
    {
        return ['period_date' => 'date', 'metadata' => 'array'];
    }
}
