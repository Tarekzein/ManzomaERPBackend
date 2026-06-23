<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;

class EmployeeProfileService
{
    public function ensureForUser(User $user): ?Employee
    {
        if (! $user->company_id) {
            return null;
        }

        return Employee::query()->firstOrCreate(
            ['company_id' => $user->company_id, 'user_id' => $user->id],
            [
                'employee_number' => $this->nextEmployeeNumber($user->company_id),
                'name' => $user->name,
                'email' => $user->email,
                'hire_date' => now()->toDateString(),
                'status' => 'active',
                'base_salary' => 0,
                'currency' => $user->company?->currency ?? config('app.currency', 'EGP'),
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
