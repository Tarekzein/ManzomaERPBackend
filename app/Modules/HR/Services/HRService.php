<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Contracts\HRRepository;
use App\Modules\HR\Models\Applicant;
use App\Modules\HR\Models\AttendanceEntry;
use App\Modules\HR\Models\Benefit;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\DisciplinaryAction;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeBenefit;
use App\Modules\HR\Models\EmployeeContract;
use App\Modules\HR\Models\EmployeeDocument;
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
use App\Modules\HR\Policies\HRPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HRService
{
    public function __construct(private HRRepository $repo, private HRPolicy $policy) {}

    public function list(User $u, string $model, array $with = [], array $filters = [])
    {
        $companyId = $this->policy->companyId($u);
        $query = $model::query()->where('company_id', $companyId)->with($with);
        $scopedEmployeeIds = $this->policy->scopedEmployeeIds($u);

        if ($scopedEmployeeIds !== []) {
            if ($model === Employee::class) {
                $query->whereIn('id', $scopedEmployeeIds);
            } elseif (in_array($model, $this->employeeScopedModels(), true)) {
                $query->whereIn('employee_id', $scopedEmployeeIds);
            }
        }

        $this->applyFilters($query, $model, $filters);

        $sort = $filters['sort'] ?? 'id';
        $direction = strtolower($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
        $query->orderBy($this->safeSortColumn($sort), $direction);

        $result = ! empty($filters['per_page'])
            ? $query->paginate(min(max((int) $filters['per_page'], 1), 100))
            : $query->get();

        return $model === Employee::class ? $this->protectPayrollFields($u, $result) : $result;
    }

    public function save(User $u, string $model, array $d, ?Model $record = null): Model
    {
        $permission = $this->permissionFor($model, $record === null);
        $company = $record ? $this->policy->ensureOwned($u, $record, $permission) : $this->policy->companyId($u, $permission);
        $this->relations($company, $d);

        return $record ? $this->repo->update($record, $d) : $this->repo->create($model, ['company_id' => $company] + $d);
    }

    public function employee(User $u, ?Employee $employee = null): Employee
    {
        if ($employee) {
            if (! $this->policy->canViewEmployee($u, $employee)) {
                throw new \Illuminate\Auth\Access\AuthorizationException('You cannot view this employee profile.');
            }

        return $this->protectEmployee($u, $employee->load(
            'department',
            'team',
            'manager',
            'reports',
            'personalDetail',
            'emergencyContacts',
            'contracts',
            'benefits.benefit',
            'onboardingTasks.assignee',
            'offboardingTasks.assignee',
            'performanceReviews.reviewer',
            'trainingRecords',
            'documents.versions'
        ));
        }

        return $this->protectEmployee($u, $this->policy->employee($u)->load(
            'department',
            'team',
            'manager',
            'personalDetail',
            'emergencyContacts',
            'contracts',
            'benefits.benefit',
            'trainingRecords',
            'documents.versions'
        ));
    }

    public function updateSelf(User $u, array $d): Employee
    {
        $employee = $this->policy->employee($u);
        $employee->update(collect($d)->only(['phone', 'address'])->all());

        if (array_key_exists('personal_detail', $d) && is_array($d['personal_detail'])) {
            EmployeePersonalDetail::updateOrCreate(
                ['company_id' => $employee->company_id, 'employee_id' => $employee->id],
                collect($d['personal_detail'])->only(['date_of_birth', 'gender', 'nationality', 'marital_status', 'address'])->all()
            );
        }

        return $this->employee($u);
    }

    public function attendance(User $u, array $d): AttendanceEntry
    {
        $company = $this->policy->companyId($u, 'hr.create');
        $this->relations($company, $d);
        if (! empty($d['clock_in']) && ! empty($d['clock_out'])) {
            $d['hours'] = round((strtotime($d['clock_out']) - strtotime($d['clock_in'])) / 3600, 2);
        }

        return AttendanceEntry::updateOrCreate(['employee_id' => $d['employee_id'], 'work_date' => $d['work_date']], ['company_id' => $company, 'recorded_by' => $u->id] + $d);
    }

    public function document(User $u, Employee $employee, array $d, UploadedFile $file): EmployeeDocument
    {
        $company = $this->policy->ensureOwned($u, $employee, 'hr.documents.edit');
        $disk = config('hr.filesystem_disk');

        return DB::transaction(function () use ($u, $employee, $d, $file, $company, $disk) {
            $doc = EmployeeDocument::firstOrCreate(['company_id' => $company, 'employee_id' => $employee->id, 'type' => $d['type'], 'name' => $d['name']]);
            $version = $doc->versions()->max('version') + 1;
            $path = $file->store("hr/employees/{$employee->id}/documents/{$doc->id}", $disk);
            $doc->versions()->create(['version' => $version, 'disk' => $disk, 'path' => $path, 'original_name' => $file->getClientOriginalName(), 'mime_type' => $file->getClientMimeType(), 'size' => $file->getSize() ?: 0, 'notes' => $d['notes'] ?? null, 'uploaded_by' => $u->id]);
            $doc->update(['current_version' => $version]);

            return $doc->load('versions');
        });
    }

    public function applicant(User $u, JobPosting $job, array $d, ?UploadedFile $resume = null): Applicant
    {
        $company = $this->policy->ensureOwned($u, $job, 'hr.create');
        if ($resume) {
            $d['resume_disk'] = config('hr.filesystem_disk');
            $d['resume_path'] = $resume->store("hr/recruitment/{$job->id}", $d['resume_disk']);
        }

        return Applicant::create(['company_id' => $company, 'job_posting_id' => $job->id] + $d);
    }

    public function downloadDocument(User $u, EmployeeDocumentVersion $version)
    {
        $version->load('document.employee');
        $employee = $version->document->employee;

        if ($employee->user_id !== $u->id) {
            $this->policy->ensureOwned($u, $version->document, 'hr.documents.view');
        } else {
            $this->policy->companyId($u);
        }

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    public function downloadResume(User $u, Applicant $applicant)
    {
        $this->policy->ensureOwned($u, $applicant, 'hr.recruitment.manage');
        abort_unless($applicant->resume_disk && $applicant->resume_path, 404, 'Applicant has no resume.');

        $extension = pathinfo($applicant->resume_path, PATHINFO_EXTENSION);

        return Storage::disk($applicant->resume_disk)->download(
            $applicant->resume_path,
            "applicant-{$applicant->id}-resume".($extension ? ".{$extension}" : '')
        );
    }

    private function relations(int $company, array $d): void
    {
        foreach (['department_id' => Department::class, 'team_id' => Team::class, 'manager_id' => Employee::class, 'manager_employee_id' => Employee::class, 'employee_id' => Employee::class, 'leave_type_id' => LeaveType::class, 'job_posting_id' => JobPosting::class, 'benefit_id' => Benefit::class, 'position_id' => Position::class] as $key => $model) {
            if (! empty($d[$key]) && ! $model::where('company_id', $company)->whereKey($d[$key])->exists()) {
                throw ValidationException::withMessages([$key => ['The selected record must belong to the company.']]);
            }
        }

        foreach (['user_id', 'assigned_to', 'reviewer_id', 'issued_by'] as $key) {
            if (! empty($d[$key]) && ! User::where('company_id', $company)->whereKey($d[$key])->exists()) {
                throw ValidationException::withMessages([$key => ['The selected user must belong to the company.']]);
            }
        }
    }

    private function employeeScopedModels(): array
    {
        return [
            AttendanceEntry::class,
            EmployeePersonalDetail::class,
            EmergencyContact::class,
            EmployeeContract::class,
            LeaveBalance::class,
            LeaveAdjustment::class,
            EmployeeBenefit::class,
            OnboardingTask::class,
            OffboardingTask::class,
            PerformanceReview::class,
            DisciplinaryAction::class,
            TrainingRecord::class,
        ];
    }

    private function permissionFor(string $model, bool $creating): string
    {
        return match ($model) {
            PerformanceReview::class => 'hr.performance.manage',
            DisciplinaryAction::class => 'hr.disciplinary.manage',
            JobPosting::class, Applicant::class => 'hr.recruitment.manage',
            default => $creating ? 'hr.create' : 'hr.edit',
        };
    }

    private function applyFilters($query, string $model, array $filters): void
    {
        if (! empty($filters['employee_id']) && in_array($model, $this->employeeScopedModels(), true)) {
            $query->where('employee_id', $filters['employee_id']);
        }

        foreach (['status', 'department_id', 'team_id', 'leave_type_id', 'year'] as $key) {
            if (array_key_exists($key, $filters) && $filters[$key] !== null && $filters[$key] !== '') {
                $query->where($key, $filters[$key]);
            }
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search === '') {
            return;
        }

        $columns = match ($model) {
            Employee::class => ['employee_number', 'name', 'email', 'phone', 'position'],
            Department::class, Team::class, Position::class, LeaveType::class => ['code', 'name'],
            Benefit::class, Holiday::class => ['name'],
            JobPosting::class => ['title'],
            Applicant::class => ['name', 'email', 'phone'],
            EmployeeContract::class => ['contract_number', 'type', 'status'],
            EmergencyContact::class => ['name', 'relationship', 'phone', 'email'],
            TrainingRecord::class, OnboardingTask::class, OffboardingTask::class => ['title', 'status'],
            PerformanceReview::class => ['period', 'status'],
            DisciplinaryAction::class => ['type', 'reason', 'status'],
            default => [],
        };

        if ($columns === []) {
            return;
        }

        $query->where(function ($query) use ($columns, $search) {
            foreach ($columns as $column) {
                $query->orWhere($column, 'like', "%{$search}%");
            }
        });
    }

    private function safeSortColumn(string $sort): string
    {
        return preg_match('/^[a-zA-Z0-9_]+$/', $sort) ? $sort : 'id';
    }

    private function protectPayrollFields(User $user, $result)
    {
        if ($user->can('hr.payroll.view') || $user->can('hr.payroll.edit')) {
            return $result;
        }

        $hide = fn (Employee $employee) => $employee->makeHidden(['base_salary', 'currency', 'payroll_formula']);

        if ($result instanceof \Illuminate\Contracts\Pagination\Paginator) {
            $result->getCollection()->transform($hide);

            return $result;
        }

        return $result->map($hide);
    }

    private function protectEmployee(User $user, Employee $employee): Employee
    {
        if (! $user->can('hr.payroll.view') && ! $user->can('hr.payroll.edit') && $employee->user_id !== $user->id) {
            $employee->makeHidden(['base_salary', 'currency', 'payroll_formula']);
            $employee->unsetRelation('benefits');
        }

        if (! $user->can('hr.performance.manage') && $employee->user_id !== $user->id) {
            $employee->unsetRelation('performanceReviews');
        }

        if (! $user->can('hr.disciplinary.manage')) {
            $employee->unsetRelation('disciplinaryActions');
        }

        return $employee;
    }
}
