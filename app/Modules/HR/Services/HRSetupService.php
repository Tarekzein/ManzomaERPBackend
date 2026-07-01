<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\Position;

class HRSetupService
{
    public function provision(Company $company): void
    {
        $department = Department::firstOrCreate(['company_id' => $company->id, 'code' => 'GENERAL'], ['name' => 'General']);
        Position::firstOrCreate(['company_id' => $company->id, 'code' => 'STAFF'], ['department_id' => $department->id, 'title' => 'Staff Member', 'is_active' => true]);
        foreach ([['ANNUAL', 'Annual Leave', 21, true], ['SICK', 'Sick Leave', 10, true], ['UNPAID', 'Unpaid Leave', 0, false]] as [$code,$name,$days,$paid]) {
            LeaveType::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name, 'annual_allowance' => $days, 'is_paid' => $paid, 'requires_approval' => true]);
        }
        Holiday::firstOrCreate(['company_id' => $company->id, 'holiday_date' => now()->startOfYear()->toDateString(), 'name' => 'New Year'], ['is_paid' => true]);
        User::where('company_id', $company->id)->each(function (User $u) use ($company, $department) {
            $employee = Employee::firstOrCreate(
                ['company_id' => $company->id, 'user_id' => $u->id],
                ['department_id' => $department->id, 'employee_number' => 'USR-'.str_pad((string) $u->id, 5, '0', STR_PAD_LEFT), 'name' => $u->name, 'email' => $u->email, 'hire_date' => now()->toDateString(), 'status' => 'active', 'employment_type' => 'full_time', 'currency' => $company->currency ?? 'EGP']
            );

            LeaveType::where('company_id', $company->id)->get()->each(
                fn (LeaveType $type) => app(LeaveBalanceService::class)->ensure($employee, $type, (int) now()->year)
            );
        });
    }
}
