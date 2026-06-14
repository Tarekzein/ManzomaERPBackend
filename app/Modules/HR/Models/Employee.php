<?php

namespace App\Modules\HR\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $table = 'hr_employees';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['address' => 'array', 'payroll_formula' => 'array', 'hire_date' => 'date', 'termination_date' => 'date', 'base_salary' => 'decimal:2'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function manager()
    {
        return $this->belongsTo(self::class, 'manager_id');
    }

    public function reports()
    {
        return $this->hasMany(self::class, 'manager_id');
    }

    public function leaveRequests()
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function attendance()
    {
        return $this->hasMany(AttendanceEntry::class);
    }

    public function payrollItems()
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function documents()
    {
        return $this->hasMany(EmployeeDocument::class);
    }
}
