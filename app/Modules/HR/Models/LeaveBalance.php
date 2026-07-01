<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveBalance extends Model
{
    protected $table = 'hr_leave_balances';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'entitled_days' => 'decimal:2',
            'carried_over_days' => 'decimal:2',
            'adjusted_days' => 'decimal:2',
            'used_days' => 'decimal:2',
            'pending_days' => 'decimal:2',
            'remaining_days' => 'decimal:2',
        ];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }
}
