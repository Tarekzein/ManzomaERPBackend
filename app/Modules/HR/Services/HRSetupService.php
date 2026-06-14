<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveType;

class HRSetupService
{
    public function provision(Company $company): void
    {
        $department = Department::firstOrCreate(['company_id' => $company->id, 'code' => 'GENERAL'], ['name' => 'General']);
        foreach ([['ANNUAL', 'Annual Leave', 21, true], ['SICK', 'Sick Leave', 10, true], ['UNPAID', 'Unpaid Leave', 0, false]] as [$code,$name,$days,$paid]) {
            LeaveType::firstOrCreate(['company_id' => $company->id, 'code' => $code], ['name' => $name, 'annual_allowance' => $days, 'is_paid' => $paid, 'requires_approval' => true]);
        }
        User::where('company_id', $company->id)->each(fn (User $u) => Employee::firstOrCreate(['company_id' => $company->id, 'user_id' => $u->id], ['department_id' => $department->id, 'employee_number' => 'USR-'.str_pad((string) $u->id, 5, '0', STR_PAD_LEFT), 'name' => $u->name, 'email' => $u->email, 'hire_date' => now()->toDateString(), 'status' => 'active', 'currency' => $company->currency ?? 'EGP']));
    }
}
