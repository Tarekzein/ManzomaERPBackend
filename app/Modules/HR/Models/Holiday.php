<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    protected $table = 'hr_holidays';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['holiday_date' => 'date', 'is_paid' => 'boolean'];
    }
}
