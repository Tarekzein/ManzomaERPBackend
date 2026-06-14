<?php

namespace App\Modules\HR\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Contracts\HRRepository;
use App\Modules\HR\Models\Applicant;
use App\Modules\HR\Models\AttendanceEntry;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmployeeDocumentVersion;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\LeaveType;
use App\Modules\HR\Models\Team;
use App\Modules\HR\Policies\HRPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class HRService
{
    public function __construct(private HRRepository $repo, private HRPolicy $policy) {}

    public function list(User $u, string $model, array $with = [])
    {
        return $this->repo->list($model, $this->policy->companyId($u), $with);
    }

    public function save(User $u, string $model, array $d, ?Model $record = null): Model
    {
        $company = $record ? $this->policy->ensureOwned($u, $record) : $this->policy->companyId($u, 'hr.create');
        $this->relations($company, $d);

        return $record ? $this->repo->update($record, $d) : $this->repo->create($model, ['company_id' => $company] + $d);
    }

    public function employee(User $u, ?Employee $employee = null): Employee
    {
        if ($employee) {
            $this->policy->ensureOwned($u, $employee);

            return $employee->load('department', 'team', 'manager', 'reports', 'documents.versions');
        }

        return $this->policy->employee($u)->load('department', 'team', 'manager', 'documents.versions');
    }

    public function updateSelf(User $u, array $d): Employee
    {
        $employee = $this->policy->employee($u);
        $employee->update(collect($d)->only(['phone', 'address'])->all());

        return $employee->refresh();
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
        $company = $this->policy->ensureOwned($u, $employee, 'hr.edit');
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
            $this->policy->ensureOwned($u, $version->document);
        } else {
            $this->policy->companyId($u);
        }

        return Storage::disk($version->disk)->download($version->path, $version->original_name);
    }

    public function downloadResume(User $u, Applicant $applicant)
    {
        $this->policy->ensureOwned($u, $applicant);
        abort_unless($applicant->resume_disk && $applicant->resume_path, 404, 'Applicant has no resume.');

        $extension = pathinfo($applicant->resume_path, PATHINFO_EXTENSION);

        return Storage::disk($applicant->resume_disk)->download(
            $applicant->resume_path,
            "applicant-{$applicant->id}-resume".($extension ? ".{$extension}" : '')
        );
    }

    private function relations(int $company, array $d): void
    {
        foreach (['department_id' => Department::class, 'team_id' => Team::class, 'manager_id' => Employee::class, 'manager_employee_id' => Employee::class, 'employee_id' => Employee::class, 'leave_type_id' => LeaveType::class, 'job_posting_id' => JobPosting::class] as $key => $model) {
            if (! empty($d[$key]) && ! $model::where('company_id', $company)->whereKey($d[$key])->exists()) {
                throw ValidationException::withMessages([$key => ['The selected record must belong to the company.']]);
            }
        }

        if (! empty($d['user_id']) && ! User::where('company_id', $company)->whereKey($d['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => ['The selected user must belong to the company.']]);
        }
    }
}
