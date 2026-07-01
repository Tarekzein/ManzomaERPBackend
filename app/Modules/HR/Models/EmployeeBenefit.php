<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class EmployeeBenefit extends Model
{
    protected $table = 'hr_employee_benefits';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function benefit()
    {
        return $this->belongsTo(Benefit::class);
    }
}
