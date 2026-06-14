<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->unsignedBigInteger('manager_employee_id')->nullable()->index();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        Schema::create('hr_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('hr_departments')->cascadeOnDelete();
            $table->unsignedBigInteger('manager_employee_id')->nullable()->index();
            $table->string('code');
            $table->string('name');
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        Schema::create('hr_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('hr_teams')->nullOnDelete();
            $table->foreignId('manager_id')->nullable()->constrained('hr_employees')->nullOnDelete();
            $table->string('employee_number');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->json('address')->nullable();
            $table->string('position')->nullable();
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->string('status')->default('active');
            $table->decimal('base_salary', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->json('payroll_formula')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'employee_number']);
            $table->unique(['company_id', 'user_id']);
            $table->index(['company_id', 'status']);
        });
        Schema::create('hr_leave_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code');
            $table->decimal('annual_allowance', 8, 2)->default(0);
            $table->boolean('is_paid')->default(true);
            $table->boolean('requires_approval')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });
        Schema::create('hr_leave_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->decimal('days', 8, 2);
            $table->text('reason')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['employee_id', 'starts_on']);
        });
        Schema::create('hr_attendance_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('work_date');
            $table->timestamp('clock_in')->nullable();
            $table->timestamp('clock_out')->nullable();
            $table->decimal('hours', 8, 2)->default(0);
            $table->string('source')->default('manual');
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['employee_id', 'work_date']);
        });
        Schema::create('hr_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('period_start');
            $table->date('period_end');
            $table->date('pay_date');
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
        Schema::create('hr_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('hr_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->decimal('base_salary', 15, 2);
            $table->decimal('bonuses', 15, 2)->default(0);
            $table->decimal('deductions', 15, 2)->default(0);
            $table->decimal('tax_withholding', 15, 2)->default(0);
            $table->decimal('gross_salary', 15, 2);
            $table->decimal('net_salary', 15, 2);
            $table->string('currency', 3);
            $table->json('breakdown')->nullable();
            $table->timestamp('emailed_at')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
        Schema::create('hr_employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();
        });
        Schema::create('hr_employee_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('hr_employee_documents')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['document_id', 'version']);
        });
        Schema::create('hr_job_postings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->date('closes_on')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });
        Schema::create('hr_applicants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_posting_id')->constrained('hr_job_postings')->cascadeOnDelete();
            $table->string('name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('stage')->default('applied');
            $table->text('notes')->nullable();
            $table->string('resume_disk')->nullable();
            $table->string('resume_path')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'stage']);
        });
    }

    public function down(): void
    {
        foreach (['hr_applicants', 'hr_job_postings', 'hr_employee_document_versions', 'hr_employee_documents', 'hr_payroll_items', 'hr_payroll_runs', 'hr_attendance_entries', 'hr_leave_requests', 'hr_leave_types', 'hr_employees', 'hr_teams', 'hr_departments'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
