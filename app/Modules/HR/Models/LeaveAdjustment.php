<?php

namespace App\Modules\HR\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeaveAdjustment extends Model
{
    protected $table = 'hr_leave_adjustments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['days' => 'decimal:2'];
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
