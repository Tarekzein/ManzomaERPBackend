<?php

namespace App\Modules\HR\Services;

use App\Modules\HR\Models\AttendanceEntry;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBenefit;
use App\Modules\HR\Models\LeaveRequest;

class PayrollCalculationService
{
    public function calculate(Employee $employee, string $periodStart, string $periodEnd, array $override = []): array
    {
        $formula = $employee->payroll_formula ?? [];
        $base = (float) $employee->base_salary;
        $benefits = (float) EmployeeBenefit::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'active')
            ->where(function ($query) use ($periodEnd) {
                $query->whereNull('starts_on')->orWhereDate('starts_on', '<=', $periodEnd);
            })
            ->where(function ($query) use ($periodStart) {
                $query->whereNull('ends_on')->orWhereDate('ends_on', '>=', $periodStart);
            })
            ->sum('amount');

        $attendanceHours = (float) AttendanceEntry::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->whereBetween('work_date', [$periodStart, $periodEnd])
            ->sum('hours');

        $unpaidLeaveDays = (float) LeaveRequest::where('company_id', $employee->company_id)
            ->where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereBetween('starts_on', [$periodStart, $periodEnd])
            ->whereHas('leaveType', fn ($query) => $query->where('is_paid', false))
            ->sum('days');

        $dailyRate = $base / max((float) ($formula['working_days'] ?? 22), 1);
        $unpaidLeaveDeduction = round($unpaidLeaveDays * $dailyRate, 2);
        $bonuses = (float) ($override['bonuses'] ?? $formula['bonuses'] ?? 0);
        $deductions = (float) ($override['deductions'] ?? $formula['deductions'] ?? 0) + $unpaidLeaveDeduction;
        $taxRate = (float) ($override['tax_rate'] ?? $formula['tax_rate'] ?? config('hr.default_tax_rate'));
        $gross = $base + $benefits + $bonuses;
        $tax = round($gross * $taxRate / 100, 2);

        return [
            'base_salary' => $base,
            'bonuses' => $bonuses + $benefits,
            'deductions' => $deductions,
            'tax_withholding' => $tax,
            'gross_salary' => $gross,
            'net_salary' => $gross - $deductions - $tax,
            'currency' => $employee->currency,
            'breakdown' => [
                'tax_rate' => $taxRate,
                'formula' => $formula,
                'benefits' => $benefits,
                'attendance_hours' => $attendanceHours,
                'unpaid_leave_days' => $unpaidLeaveDays,
                'unpaid_leave_deduction' => $unpaidLeaveDeduction,
                'finance_posting_status' => 'ready',
            ],
        ];
    }
}
