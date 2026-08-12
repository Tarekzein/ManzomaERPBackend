<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Models\Employee;

class EmployeeProfileService
{
    public function ensureForUser(User $user, ?Company $company = null): ?Employee
    {
        $company ??= $user->company;
        if (! $company) {
            return null;
        }

        $companyId = (int) $company->getKey();

        return Employee::query()->firstOrCreate(
            ['company_id' => $companyId, 'user_id' => $user->id],
            [
                'employee_number' => $this->nextEmployeeNumber($companyId),
                'name' => $user->name,
                'email' => $user->email,
                'hire_date' => now()->toDateString(),
                'status' => 'active',
                'base_salary' => 0,
                'currency' => $company->currency ?? config('app.currency', 'EGP'),
                'payroll_formula' => ['bonuses' => 0, 'deductions' => 0, 'tax_rate' => 0],
            ]
        );
    }

    private function nextEmployeeNumber(int $companyId): string
    {
        $next = Employee::query()->where('company_id', $companyId)->count() + 1;

        do {
            $number = 'EMP-'.str_pad((string) $next, 5, '0', STR_PAD_LEFT);
            $next++;
        } while (Employee::query()->where('company_id', $companyId)->where('employee_number', $number)->exists());

        return $number;
    }
}
