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
        return [
            'address' => 'array',
            'payroll_formula' => 'array',
            'hire_date' => 'date',
            'probation_ends_on' => 'date',
            'termination_date' => 'date',
            'resignation_date' => 'date',
            'base_salary' => 'decimal:2',
        ];
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

    public function personalDetail()
    {
        return $this->hasOne(EmployeePersonalDetail::class);
    }

    public function emergencyContacts()
    {
        return $this->hasMany(EmergencyContact::class);
    }

    public function contracts()
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function benefits()
    {
        return $this->hasMany(EmployeeBenefit::class);
    }

    public function onboardingTasks()
    {
        return $this->hasMany(OnboardingTask::class);
    }

    public function offboardingTasks()
    {
        return $this->hasMany(OffboardingTask::class);
    }

    public function performanceReviews()
    {
        return $this->hasMany(PerformanceReview::class);
    }

    public function disciplinaryActions()
    {
        return $this->hasMany(DisciplinaryAction::class);
    }

    public function trainingRecords()
    {
        return $this->hasMany(TrainingRecord::class);
    }
}
