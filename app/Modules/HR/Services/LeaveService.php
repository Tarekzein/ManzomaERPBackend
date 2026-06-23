<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Policies\HRPolicy;
use App\Modules\Notifications\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(private HRPolicy $policy, private NotificationService $notifications) {}

    public function list(User $u)
    {
        $companyId = $this->policy->companyId($u);
        $employeeIds = $this->policy->scopedEmployeeIds($u);

        return LeaveRequest::with('employee', 'leaveType', 'reviewer')
            ->where('company_id', $companyId)
            ->when($employeeIds !== [], fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->latest()
            ->get();
    }

    public function mine(User $u)
    {
        return $this->policy->employee($u)->leaveRequests()->with('leaveType', 'reviewer')->latest()->get();
    }

    public function request(User $u, array $d): LeaveRequest
    {
        $employee = $this->policy->employee($u);
        $leaveType = LeaveType::where('company_id', $employee->company_id)->find($d['leave_type_id']);
        if (! $leaveType) {
            throw ValidationException::withMessages(['leave_type_id' => ['The selected leave type must belong to the company.']]);
        }

        $days = Carbon::parse($d['starts_on'])->diffInDays(Carbon::parse($d['ends_on'])) + 1;
        $overlaps = $employee->leaveRequests()
            ->whereIn('status', ['pending', 'approved'])
            ->whereDate('starts_on', '<=', $d['ends_on'])
            ->whereDate('ends_on', '>=', $d['starts_on'])
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_on' => ['The leave request overlaps an existing request.']]);
        }

        $used = (float) $employee->leaveRequests()
            ->where('leave_type_id', $leaveType->id)
            ->where('status', 'approved')
            ->whereYear('starts_on', Carbon::parse($d['starts_on'])->year)
            ->sum('days');

        if ((float) $leaveType->annual_allowance > 0 && $used + $days > (float) $leaveType->annual_allowance) {
            throw ValidationException::withMessages(['ends_on' => ['The leave request exceeds the annual allowance.']]);
        }

        $request = LeaveRequest::create([
            'company_id' => $employee->company_id,
            'employee_id' => $employee->id,
            'days' => $days,
            'status' => $leaveType->requires_approval ? 'pending' : 'approved',
        ] + $d);

        $approvers = User::where('company_id', $employee->company_id)->permission('hr.edit')->get();
        $this->notifications->send($approvers, 'hr.leave.requested', 'Leave approval required', "{$employee->name} submitted a leave request.", ['leave_request_id' => $request->id], '/hr', 'warning');

        return $request;
    }

    public function review(User $u, LeaveRequest $r, array $d): LeaveRequest
    {
        $this->policy->ensureOwned($u, $r);
        if ($r->status !== 'pending') {
            throw ValidationException::withMessages(['status' => ['Only pending leave requests can be reviewed.']]);
        }

        $r->load('employee.manager');
        $this->policy->canReview($u, $r->employee);
        $r->update(['status' => $d['status'], 'review_notes' => $d['review_notes'] ?? null, 'reviewed_by' => $u->id, 'reviewed_at' => now()]);
        if ($r->employee->user) {
            $this->notifications->send($r->employee->user, 'hr.leave.reviewed', 'Leave request reviewed', "Your leave request was {$r->status}.", ['leave_request_id' => $r->id], '/hr', $r->status === 'approved' ? 'success' : 'warning');
        }

        return $r->load('employee', 'leaveType', 'reviewer');
    }
}
