<?php

namespace App\Modules\HR\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\HR\Http\Requests\HRRequest;
use App\Modules\HR\Models\Applicant;
use App\Modules\HR\Models\AttendanceEntry;
use App\Modules\HR\Models\Benefit;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DisciplinaryAction;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBenefit;
use App\Modules\HR\Models\EmployeeContract;
use App\Modules\HR\Models\EmployeeDocumentVersion;
use App\Modules\HR\Models\EmployeePersonalDetail;
use App\Modules\HR\Models\EmergencyContact;
use App\Modules\HR\Models\Holiday;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\LeaveAdjustment;
use App\Modules\HR\Models\LeaveBalance;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\OffboardingTask;
use App\Modules\HR\Models\OnboardingTask;
use App\Modules\HR\Models\PerformanceReview;
use App\Modules\HR\Models\Position;
use App\Modules\HR\Models\Team;
use App\Modules\HR\Models\TrainingRecord;
use App\Modules\HR\Services\LeaveBalanceService;
use App\Modules\HR\Services\HRService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class HRController extends Controller
{
    public function __construct(private HRService $hr, private LeaveBalanceService $leaveBalances) {}

    public function departments(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Department::class, ['children', 'teams', 'employees'], $r->query()));
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
        return ApiResponse::success($this->hr->list($r->user(), Team::class, ['department', 'employees'], $r->query()));
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
        return ApiResponse::success($this->hr->list($r->user(), Employee::class, ['user', 'department', 'team', 'manager'], $r->query()));
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
        return ApiResponse::success($this->hr->list($r->user(), LeaveType::class, [], $r->query()));
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
        return ApiResponse::success($this->hr->list($r->user(), AttendanceEntry::class, ['employee'], $r->query()));
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
        return ApiResponse::success($this->hr->list($r->user(), JobPosting::class, ['department', 'applicants'], $r->query()));
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

    public function positions(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Position::class, ['department'], $r->query()));
    }

    public function storePosition(HRRequest $r)
    {
        return ApiResponse::success($this->hr->save($r->user(), Position::class, $r->validated()), 'Position created', status: 201);
    }

    public function updatePosition(HRRequest $r, Position $position)
    {
        return ApiResponse::success($this->hr->save($r->user(), Position::class, $r->validated(), $position), 'Position updated');
    }

    public function personalDetails(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), EmployeePersonalDetail::class, ['employee'], $r->query()));
    }

    public function savePersonalDetail(HRRequest $r, ?EmployeePersonalDetail $personalDetail = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), EmployeePersonalDetail::class, $r->validated(), $personalDetail), $personalDetail ? 'Personal details updated' : 'Personal details saved', status: $personalDetail ? 200 : 201);
    }

    public function emergencyContacts(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), EmergencyContact::class, ['employee'], $r->query()));
    }

    public function saveEmergencyContact(HRRequest $r, ?EmergencyContact $contact = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), EmergencyContact::class, $r->validated(), $contact), $contact ? 'Emergency contact updated' : 'Emergency contact created', status: $contact ? 200 : 201);
    }

    public function contracts(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), EmployeeContract::class, ['employee'], $r->query()));
    }

    public function saveContract(HRRequest $r, ?EmployeeContract $contract = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), EmployeeContract::class, $r->validated(), $contract), $contract ? 'Contract updated' : 'Contract created', status: $contract ? 200 : 201);
    }

    public function holidays(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Holiday::class, [], $r->query()));
    }

    public function saveHoliday(HRRequest $r, ?Holiday $holiday = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), Holiday::class, $r->validated(), $holiday), $holiday ? 'Holiday updated' : 'Holiday created', status: $holiday ? 200 : 201);
    }

    public function leaveBalances(Request $r)
    {
        return ApiResponse::success($this->leaveBalances->balances($r->user(), $r->integer('employee_id') ?: null, $r->integer('year') ?: null));
    }

    public function adjustLeaveBalance(HRRequest $r)
    {
        return ApiResponse::success($this->leaveBalances->adjust($r->user(), $r->validated()), 'Leave balance adjusted', status: 201);
    }

    public function benefits(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), Benefit::class, [], $r->query()));
    }

    public function saveBenefit(HRRequest $r, ?Benefit $benefit = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), Benefit::class, $r->validated(), $benefit), $benefit ? 'Benefit updated' : 'Benefit created', status: $benefit ? 200 : 201);
    }

    public function employeeBenefits(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), EmployeeBenefit::class, ['employee', 'benefit'], $r->query()));
    }

    public function saveEmployeeBenefit(HRRequest $r, ?EmployeeBenefit $employeeBenefit = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), EmployeeBenefit::class, $r->validated(), $employeeBenefit), $employeeBenefit ? 'Employee benefit updated' : 'Employee benefit assigned', status: $employeeBenefit ? 200 : 201);
    }

    public function onboarding(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), OnboardingTask::class, ['employee', 'assignee'], $r->query()));
    }

    public function saveOnboarding(HRRequest $r, ?OnboardingTask $task = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), OnboardingTask::class, $r->validated(), $task), $task ? 'Onboarding task updated' : 'Onboarding task created', status: $task ? 200 : 201);
    }

    public function offboarding(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), OffboardingTask::class, ['employee', 'assignee'], $r->query()));
    }

    public function saveOffboarding(HRRequest $r, ?OffboardingTask $task = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), OffboardingTask::class, $r->validated(), $task), $task ? 'Offboarding task updated' : 'Offboarding task created', status: $task ? 200 : 201);
    }

    public function performanceReviews(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), PerformanceReview::class, ['employee', 'reviewer'], $r->query()));
    }

    public function savePerformanceReview(HRRequest $r, ?PerformanceReview $review = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), PerformanceReview::class, $r->validated(), $review), $review ? 'Performance review updated' : 'Performance review created', status: $review ? 200 : 201);
    }

    public function disciplinaryActions(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), DisciplinaryAction::class, ['employee', 'issuer'], $r->query()));
    }

    public function saveDisciplinaryAction(HRRequest $r, ?DisciplinaryAction $action = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), DisciplinaryAction::class, $r->validated(), $action), $action ? 'Disciplinary action updated' : 'Disciplinary action created', status: $action ? 200 : 201);
    }

    public function trainingRecords(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), TrainingRecord::class, ['employee'], $r->query()));
    }

    public function saveTrainingRecord(HRRequest $r, ?TrainingRecord $record = null)
    {
        return ApiResponse::success($this->hr->save($r->user(), TrainingRecord::class, $r->validated(), $record), $record ? 'Training record updated' : 'Training record created', status: $record ? 200 : 201);
    }

    public function meLeaveBalances(Request $r)
    {
        return ApiResponse::success($this->leaveBalances->balances($r->user(), null, $r->integer('year') ?: null));
    }

    public function meAttendanceSummary(Request $r)
    {
        $employee = $this->hr->employee($r->user());
        $from = $r->date('from')?->toDateString() ?? now()->startOfMonth()->toDateString();
        $to = $r->date('to')?->toDateString() ?? now()->endOfMonth()->toDateString();
        $entries = AttendanceEntry::where('company_id', $employee->company_id)->where('employee_id', $employee->id)->whereBetween('work_date', [$from, $to])->latest('work_date')->get();

        return ApiResponse::success([
            'from' => $from,
            'to' => $to,
            'total_hours' => (float) $entries->sum('hours'),
            'entries' => $entries,
        ]);
    }

    public function meTraining(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), TrainingRecord::class, [], ['employee_id' => $this->hr->employee($r->user())->id] + $r->query()));
    }

    public function mePerformance(Request $r)
    {
        return ApiResponse::success($this->hr->list($r->user(), PerformanceReview::class, ['reviewer'], ['employee_id' => $this->hr->employee($r->user())->id] + $r->query()));
    }

    public function downloadResume(Request $r, Applicant $applicant)
    {
        return $this->hr->downloadResume($r->user(), $applicant);
    }
}
