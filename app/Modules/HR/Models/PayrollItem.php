<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollItem extends Model
{
    protected $table = 'hr_payroll_items';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['breakdown' => 'array', 'emailed_at' => 'datetime', 'base_salary' => 'decimal:2', 'bonuses' => 'decimal:2', 'deductions' => 'decimal:2', 'tax_withholding' => 'decimal:2', 'gross_salary' => 'decimal:2', 'net_salary' => 'decimal:2'];
    }

    public function run()
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }
}
