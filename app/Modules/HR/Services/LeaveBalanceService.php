<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\LeaveAdjustment;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Policies\HRPolicy;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Validation\ValidationException;

class LeaveBalanceService
{
    public function __construct(private readonly HRPolicy $policy) {}

    public function businessDays(Employee $employee, string $startsOn, string $endsOn): float
    {
        $start = Carbon::parse($startsOn)->startOfDay();
        $end = Carbon::parse($endsOn)->startOfDay();

        if ($end->lt($start)) {
            return 0;
        }

        $holidays = Holiday::where('company_id', $employee->company_id)
            ->whereBetween('holiday_date', [$start->toDateString(), $end->toDateString()])
            ->pluck('holiday_date')
            ->map(fn ($date) => Carbon::parse($date)->toDateString())
            ->all();

        $days = 0;
        foreach (CarbonPeriod::create($start, $end) as $date) {
            if (! $date->isWeekend() && ! in_array($date->toDateString(), $holidays, true)) {
                $days++;
            }
        }

        return (float) max($days, 0);
    }

    public function ensure(Employee $employee, LeaveType $type, int $year): LeaveBalance
    {
        $balance = LeaveBalance::firstOrCreate(
            ['company_id' => $employee->company_id, 'employee_id' => $employee->id, 'leave_type_id' => $type->id, 'year' => $year],
            ['entitled_days' => $type->annual_allowance, 'remaining_days' => $type->annual_allowance]
        );

        return $this->sync($balance);
    }

    public function sync(LeaveBalance $balance): LeaveBalance
    {
        $used = (float) LeaveRequest::where('company_id', $balance->company_id)
            ->where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->whereYear('starts_on', $balance->year)
            ->where('status', 'approved')
            ->sum('days');

        $pending = (float) LeaveRequest::where('company_id', $balance->company_id)
            ->where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->whereYear('starts_on', $balance->year)
            ->where('status', 'pending')
            ->sum('days');

        $adjusted = (float) LeaveAdjustment::where('company_id', $balance->company_id)
            ->where('employee_id', $balance->employee_id)
            ->where('leave_type_id', $balance->leave_type_id)
            ->where('year', $balance->year)
            ->sum('days');

        $remaining = (float) $balance->entitled_days + (float) $balance->carried_over_days + $adjusted - $used - $pending;
        $balance->update([
            'used_days' => $used,
            'pending_days' => $pending,
            'adjusted_days' => $adjusted,
            'remaining_days' => $remaining,
        ]);

        return $balance->refresh()->load('leaveType');
    }

    public function balances(User $user, ?int $employeeId = null, ?int $year = null)
    {
        $year ??= (int) now()->year;
        $employee = $employeeId
            ? Employee::where('company_id', $this->policy->companyId($user))->findOrFail($employeeId)
            : $this->policy->employee($user);

        if (! $this->policy->canViewEmployee($user, $employee)) {
            throw new \Illuminate\Auth\Access\AuthorizationException('You cannot view these leave balances.');
        }

        return LeaveType::where('company_id', $employee->company_id)
            ->get()
            ->map(fn (LeaveType $type) => $this->ensure($employee, $type, $year))
            ->values();
    }

    public function adjust(User $user, array $data): LeaveBalance
    {
        $companyId = $this->policy->companyId($user, 'hr.edit');
        $employee = Employee::where('company_id', $companyId)->find($data['employee_id']);
        $type = LeaveType::where('company_id', $companyId)->find($data['leave_type_id']);

        if (! $employee || ! $type) {
            throw ValidationException::withMessages(['employee_id' => ['Employee and leave type must belong to the company.']]);
        }

        LeaveAdjustment::create([
            'company_id' => $companyId,
            'created_by' => $user->id,
        ] + $data);

        return $this->ensure($employee, $type, (int) $data['year']);
    }
}
