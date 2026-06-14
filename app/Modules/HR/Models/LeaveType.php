<?php

namespace App\Modules\HR\Models;

use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    protected $table = 'hr_leave_types';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'requires_approval' => 'boolean', 'annual_allowance' => 'decimal:2'];
    }
}
