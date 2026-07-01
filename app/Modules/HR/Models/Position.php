<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class Position extends Model
{
    protected $table = 'hr_positions';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'min_salary' => 'decimal:2', 'max_salary' => 'decimal:2'];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }
}
