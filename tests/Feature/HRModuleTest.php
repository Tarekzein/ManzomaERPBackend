<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\PayrollItem;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HRModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_organization_employee_attendance_and_self_service_work(): void
    {
        $admin = $this->admin();
        $department = $this->postJson('/api/hr/departments', ['code' => 'ENG', 'name' => 'Engineering'])->assertCreated()->json('data');
        $team = $this->postJson('/api/hr/teams', ['department_id' => $department['id'], 'code' => 'API', 'name' => 'API Team'])->assertCreated()->json('data');
        $employee = $this->postJson('/api/hr/employees', [
            'department_id' => $department['id'], 'team_id' => $team['id'], 'employee_number' => 'EMP-001',
            'name' => 'Employee One', 'email' => 'employee@example.com', 'position' => 'Engineer', 'hire_date' => '2026-01-01',
            'status' => 'active', 'base_salary' => 10000, 'currency' => 'EGP',
        ])->assertCreated()->json('data');

        $this->postJson('/api/hr/attendance', [
            'employee_id' => $employee['id'], 'work_date' => '2026-06-11',
            'clock_in' => '2026-06-11 09:00:00', 'clock_out' => '2026-06-11 17:00:00', 'source' => 'manual',
        ])->assertCreated()->assertJsonPath('data.hours', '8.00');

        $this->getJson('/api/hr/departments')->assertOk()->assertJsonPath('data.0.children', []);
        $this->getJson('/api/hr/me')->assertOk()->assertJsonPath('data.user_id', $admin->id);
        $this->putJson('/api/hr/me', ['phone' => '+201000000000'])->assertOk()->assertJsonPath('data.phone', '+201000000000');
    }

    public function test_leave_workflow_notifies_employee(): void
    {
        $admin = $this->admin();
        $type = $this->getJson('/api/hr/leave-types')->assertOk()->json('data.0');
        $request = $this->postJson('/api/hr/leave-requests', ['leave_type_id' => $type['id'], 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-03', 'reason' => 'Holiday'])
            ->assertCreated()->assertJsonPath('data.days', '3.00')->json('data');
        $this->postJson('/api/hr/leave-requests', ['leave_type_id' => $type['id'], 'starts_on' => '2026-07-02', 'ends_on' => '2026-07-04'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_on');
        $this->postJson("/api/hr/leave-requests/{$request['id']}/review", ['status' => 'approved', 'review_notes' => 'Approved'])
            ->assertOk()->assertJsonPath('data.status', 'approved');
        $this->postJson("/api/hr/leave-requests/{$request['id']}/review", ['status' => 'rejected'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
        $this->getJson('/api/hr/me/leave-requests')->assertOk()->assertJsonPath('data.0.status', 'approved');
    }

    public function test_payroll_payslip_email_and_reports_work(): void
    {
        $admin = $this->admin();
        Employee::where('user_id', $admin->id)->update(['base_salary' => 10000, 'payroll_formula' => ['bonuses' => 1000, 'deductions' => 500, 'tax_rate' => 10]]);
        $run = $this->postJson('/api/hr/payroll-runs', ['name' => 'June 2026', 'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'pay_date' => '2026-06-30'])->assertCreated()->json('data');
        $this->postJson("/api/hr/payroll-runs/{$run['id']}/process", [])->assertOk()->assertJsonPath('data.items.0.net_salary', '9400.00');
        $this->postJson("/api/hr/payroll-runs/{$run['id']}/process", [])->assertUnprocessable()->assertJsonValidationErrors('status');
        $item = PayrollItem::firstOrFail();

        $this->get("/api/hr/payslips/{$item->id}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        Mail::fake();
        $this->postJson("/api/hr/payslips/{$item->id}/email")->assertOk();
        $activeEmployees = Employee::where('company_id', $admin->company_id)->where('status', 'active')->count();
        $this->getJson('/api/hr/reports/headcount')->assertOk()->assertJsonPath('data.total', $activeEmployees);
        $this->get('/api/hr/reports/payroll-summary?format=csv')
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=payroll-summary.csv')
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_documents_and_basic_recruitment_work(): void
    {
        $admin = $this->admin();
        Storage::fake('local');
        config(['hr.filesystem_disk' => 'local']);
        $employee = Employee::where('user_id', $admin->id)->firstOrFail();
        $this->post("/api/hr/employees/{$employee->id}/documents", ['type' => 'contract', 'name' => 'Contract', 'file' => UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.current_version', 1);
        $document = $this->post("/api/hr/employees/{$employee->id}/documents", ['type' => 'contract', 'name' => 'Contract', 'file' => UploadedFile::fake()->create('contract-v2.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()->assertJsonPath('data.current_version', 2)->json('data');
        $this->get("/api/hr/documents/versions/{$document['versions'][1]['id']}/download")->assertDownload('contract-v2.pdf');

        $job = $this->postJson('/api/hr/jobs', ['title' => 'Backend Engineer', 'status' => 'open'])->assertCreated()->json('data');
        $applicant = $this->post("/api/hr/jobs/{$job['id']}/applicants", [
            'name' => 'Candidate',
            'email' => 'candidate@example.com',
            'resume' => UploadedFile::fake()->create('resume.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data');
        $this->get("/api/hr/applicants/{$applicant['id']}/resume")->assertDownload("applicant-{$applicant['id']}-resume.pdf");
        $this->putJson("/api/hr/applicants/{$applicant['id']}", ['stage' => 'interview', 'notes' => 'Strong candidate'])->assertOk()->assertJsonPath('data.stage', 'interview');
    }

    public function test_professional_hr_suite_endpoints_work(): void
    {
        $admin = $this->admin();
        $employee = Employee::where('user_id', $admin->id)->firstOrFail();
        $departmentId = $employee->department_id;

        $this->postJson('/api/hr/positions', [
            'department_id' => $departmentId,
            'code' => 'HRM',
            'title' => 'HR Manager',
            'description' => 'Owns people operations.',
            'min_salary' => 10000,
            'max_salary' => 20000,
            'is_active' => true,
        ])->assertCreated()->assertJsonPath('data.title', 'HR Manager');

        $this->postJson('/api/hr/holidays', [
            'name' => 'Company Day',
            'holiday_date' => '2026-07-23',
            'is_paid' => true,
            'region' => 'EG',
        ])->assertCreated()->assertJsonPath('data.name', 'Company Day');

        $contract = $this->postJson('/api/hr/contracts', [
            'employee_id' => $employee->id,
            'contract_number' => 'CON-001',
            'type' => 'employment',
            'starts_on' => '2026-01-01',
            'salary' => 10000,
            'currency' => 'EGP',
            'status' => 'active',
        ])->assertCreated()->json('data');
        $this->getJson('/api/hr/contracts?search=CON-001')->assertOk()->assertJsonPath('data.0.id', $contract['id']);

        $benefit = $this->postJson('/api/hr/benefits', [
            'name' => 'Transport Allowance',
            'type' => 'allowance',
            'default_amount' => 500,
            'taxable' => false,
            'is_active' => true,
        ])->assertCreated()->json('data');
        $this->postJson('/api/hr/employee-benefits', [
            'employee_id' => $employee->id,
            'benefit_id' => $benefit['id'],
            'amount' => 500,
            'starts_on' => '2026-06-01',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.amount', '500.00');

        $leaveType = $this->getJson('/api/hr/leave-types')->assertOk()->json('data.0');
        $this->getJson("/api/hr/leave-balances?employee_id={$employee->id}&year=2026")
            ->assertOk()
            ->assertJsonPath('data.0.year', 2026);
        $this->postJson('/api/hr/leave-balances/adjustments', [
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType['id'],
            'year' => 2026,
            'days' => 2,
            'reason' => 'Opening balance correction',
        ])->assertCreated()->assertJsonPath('data.adjusted_days', '2.00');

        $this->postJson('/api/hr/onboarding-tasks', [
            'employee_id' => $employee->id,
            'title' => 'Sign handbook',
            'assigned_to' => $admin->id,
            'due_on' => '2026-07-01',
            'status' => 'pending',
        ])->assertCreated()->assertJsonPath('data.title', 'Sign handbook');

        $this->postJson('/api/hr/performance-reviews', [
            'employee_id' => $employee->id,
            'reviewer_id' => $admin->id,
            'period' => '2026 H1',
            'score' => 90,
            'status' => 'completed',
            'summary' => 'Strong performance.',
            'reviewed_on' => '2026-06-30',
        ])->assertCreated()->assertJsonPath('data.score', '90.00');

        $this->postJson('/api/hr/disciplinary-actions', [
            'employee_id' => $employee->id,
            'issued_by' => $admin->id,
            'type' => 'warning',
            'reason' => 'Policy reminder',
            'issued_on' => '2026-06-30',
            'status' => 'open',
        ])->assertCreated()->assertJsonPath('data.type', 'warning');

        $this->postJson('/api/hr/training-records', [
            'employee_id' => $employee->id,
            'title' => 'Safety Training',
            'provider' => 'Internal',
            'started_on' => '2026-06-01',
            'completed_on' => '2026-06-05',
            'status' => 'completed',
            'cost' => 250,
            'currency' => 'EGP',
        ])->assertCreated()->assertJsonPath('data.title', 'Safety Training');

        $this->getJson('/api/hr/me/leave-balances')->assertOk();
        $this->getJson('/api/hr/me/attendance-summary')->assertOk()->assertJsonStructure(['data' => ['from', 'to', 'total_hours', 'entries']]);
        $this->getJson('/api/hr/reports/contracts')->assertOk()->assertJsonPath('data.0.employee', $employee->name);
        $this->getJson('/api/hr/reports/training')->assertOk()->assertJsonPath('data.0.title', 'Safety Training');
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
