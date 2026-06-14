<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HRRequest;
use App\Modules\HR\Models\Applicant;
use App\Modules\HR\Models\AttendanceEntry;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocumentVersion;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\Team;
use App\Modules\HR\Services\HRService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class HRController extends Controller
{
    public function __construct(private HRService $hr) {}

    public function departments(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Department::class, ['children', 'teams', 'employees']));
    }

    public function storeDepartment(HRRequest $r)
    {
        return ApiResponse::success($this->hr->save($r->user(), Department::class, $r->validated()), 'Department created', status: 201);
    }

    public function updateDepartment(HRRequest $r, Department $department)
    {
        return ApiResponse::success($this->hr->save($r->user(), Department::class, $r->validated(), $department), 'Department updated');
    }

    public function teams(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Team::class, ['department', 'employees']));
    }

    public function storeTeam(HRRequest $r)
    {
        return ApiResponse::success($this->hr->save($r->user(), Team::class, $r->validated()), 'Team created', status: 201);
    }

    public function updateTeam(HRRequest $r, Team $team)
    {
        return ApiResponse::success($this->hr->save($r->user(), Team::class, $r->validated(), $team), 'Team updated');
    }

    public function employees(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Employee::class, ['user', 'department', 'team', 'manager']));
    }

    public function showEmployee(Request $r, Employee $employee)
    {
        return ApiResponse::success($this->hr->employee($r->user(), $employee));
    }

    public function storeEmployee(HRRequest $r)
    {
        return ApiResponse::success($this->hr->save($r->user(), Employee::class, $r->validated()), 'Employee created', status: 201);
    }

    public function updateEmployee(HRRequest $r, Employee $employee)
    {
        return ApiResponse::success($this->hr->save($r->user(), Employee::class, $r->validated(), $employee), 'Employee updated');
    }

    public function leaveTypes(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), LeaveType::class));
    }

    public function storeLeaveType(HRRequest $r)
    {
        return ApiResponse::success($this->hr->save($r->user(), LeaveType::class, $r->validated()), 'Leave type created', status: 201);
    }

    public function updateLeaveType(HRRequest $r, LeaveType $leaveType)
    {
        return ApiResponse::success($this->hr->save($r->user(), LeaveType::class, $r->validated(), $leaveType), 'Leave type updated');
    }

    public function attendance(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), AttendanceEntry::class, ['employee']));
    }

    public function storeAttendance(HRRequest $r)
    {
        return ApiResponse::success($this->hr->attendance($r->user(), $r->validated()), 'Attendance recorded', status: 201);
    }

    public function me(Request $r)
    {
        return ApiResponse::success($this->hr->employee($r->user()));
    }

    public function updateMe(HRRequest $r)
    {
        return ApiResponse::success($this->hr->updateSelf($r->user(), $r->validated()), 'Profile updated');
    }

    public function document(HRRequest $r, Employee $employee)
    {
        return ApiResponse::success($this->hr->document($r->user(), $employee, $r->safe()->except('file'), $r->file('file')), 'Employee document version stored', status: 201);
    }

    public function downloadDocument(Request $r, EmployeeDocumentVersion $version)
    {
        return $this->hr->downloadDocument($r->user(), $version);
    }

    public function jobs(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), JobPosting::class, ['department', 'applicants']));
    }

    public function storeJob(HRRequest $r)
    {
        return ApiResponse::success($this->hr->save($r->user(), JobPosting::class, $r->validated()), 'Job posting created', status: 201);
    }

    public function updateJob(HRRequest $r, JobPosting $job)
    {
        return ApiResponse::success($this->hr->save($r->user(), JobPosting::class, $r->validated(), $job), 'Job posting updated');
    }

    public function applicant(HRRequest $r, JobPosting $job)
    {
        return ApiResponse::success($this->hr->applicant($r->user(), $job, $r->safe()->except('resume'), $r->file('resume')), 'Applicant added', status: 201);
    }

    public function updateApplicant(HRRequest $r, Applicant $applicant)
    {
        return ApiResponse::success($this->hr->save($r->user(), Applicant::class, $r->validated(), $applicant), 'Applicant stage updated');
    }

    public function downloadResume(Request $r, Applicant $applicant)
    {
        return $this->hr->downloadResume($r->user(), $applicant);
    }
}
