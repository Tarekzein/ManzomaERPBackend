<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employees', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_employees', 'employment_type')) {
                $table->string('employment_type')->default('full_time')->after('position');
                $table->string('work_location')->nullable()->after('employment_type');
                $table->date('probation_ends_on')->nullable()->after('hire_date');
                $table->date('resignation_date')->nullable()->after('termination_date');
                $table->foreignId('created_by')->nullable()->after('payroll_formula')->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            }
        });

        Schema::create('hr_positions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('hr_departments')->nullOnDelete();
            $table->string('code');
            $table->string('title');
            $table->text('description')->nullable();
            $table->decimal('min_salary', 15, 2)->nullable();
            $table->decimal('max_salary', 15, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('hr_employee_personal_details', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->date('date_of_birth')->nullable();
            $table->string('gender')->nullable();
            $table->string('nationality')->nullable();
            $table->string('national_id')->nullable();
            $table->string('marital_status')->nullable();
            $table->json('address')->nullable();
            $table->timestamps();
            $table->unique('employee_id');
        });

        Schema::create('hr_emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('phone');
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('hr_employee_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('contract_number');
            $table->string('type')->default('employment');
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->decimal('salary', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('status')->default('active');
            $table->json('terms')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'contract_number']);
        });

        Schema::create('hr_holidays', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->date('holiday_date');
            $table->boolean('is_paid')->default(true);
            $table->string('region')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'holiday_date', 'name']);
        });

        Schema::create('hr_leave_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('entitled_days', 8, 2)->default(0);
            $table->decimal('carried_over_days', 8, 2)->default(0);
            $table->decimal('adjusted_days', 8, 2)->default(0);
            $table->decimal('used_days', 8, 2)->default(0);
            $table->decimal('pending_days', 8, 2)->default(0);
            $table->decimal('remaining_days', 8, 2)->default(0);
            $table->timestamps();
            $table->unique(['employee_id', 'leave_type_id', 'year']);
        });

        Schema::create('hr_leave_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('hr_leave_types')->restrictOnDelete();
            $table->unsignedSmallInteger('year');
            $table->decimal('days', 8, 2);
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('hr_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type')->default('allowance');
            $table->decimal('default_amount', 15, 2)->default(0);
            $table->boolean('taxable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('hr_employee_benefits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('benefit_id')->constrained('hr_benefits')->restrictOnDelete();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('hr_onboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_offboarding_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_performance_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('period');
            $table->decimal('score', 5, 2)->nullable();
            $table->string('status')->default('draft');
            $table->json('goals')->nullable();
            $table->text('summary')->nullable();
            $table->date('reviewed_on')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_disciplinary_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type');
            $table->text('reason');
            $table->date('issued_on');
            $table->string('status')->default('open');
            $table->text('resolution')->nullable();
            $table->timestamps();
        });

        Schema::create('hr_training_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
            $table->string('title');
            $table->string('provider')->nullable();
            $table->date('started_on')->nullable();
            $table->date('completed_on')->nullable();
            $table->string('status')->default('planned');
            $table->decimal('cost', 15, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach ([
            'hr_training_records',
            'hr_disciplinary_actions',
            'hr_performance_reviews',
            'hr_offboarding_tasks',
            'hr_onboarding_tasks',
            'hr_employee_benefits',
            'hr_benefits',
            'hr_leave_adjustments',
            'hr_leave_balances',
            'hr_holidays',
            'hr_employee_contracts',
            'hr_emergency_contacts',
            'hr_employee_personal_details',
            'hr_positions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::table('hr_employees', function (Blueprint $table) {
            foreach (['updated_by', 'created_by'] as $column) {
                if (Schema::hasColumn('hr_employees', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            foreach (['employment_type', 'work_location', 'probation_ends_on', 'resignation_date'] as $column) {
                if (Schema::hasColumn('hr_employees', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
