<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeContract extends Model
{
    protected $table = 'hr_employee_contracts';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'salary' => 'decimal:2', 'terms' => 'array'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
