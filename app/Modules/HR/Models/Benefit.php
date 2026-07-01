<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class Benefit extends Model
{
    protected $table = 'hr_benefits';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['default_amount' => 'decimal:2', 'taxable' => 'boolean', 'is_active' => 'boolean'];
    }
}
