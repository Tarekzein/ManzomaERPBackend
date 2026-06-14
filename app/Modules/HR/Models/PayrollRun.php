<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollRun extends Model
{
    protected $table = 'hr_payroll_runs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'pay_date' => 'date', 'processed_at' => 'datetime'];
    }

    public function items()
    {
        return $this->hasMany(PayrollItem::class);
    }
}
