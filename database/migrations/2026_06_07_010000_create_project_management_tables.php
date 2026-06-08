<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('budget', 15, 2)->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'owner_id']);
        });

        Schema::create('project_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('priority', 16)->default('medium');
            $table->string('status', 32)->default('to_do');
            $table->decimal('estimated_hours', 8, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'assignee_id']);
            $table->index(['project_id', 'sort_order']);
        });

        Schema::create('project_time_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->date('work_date');
            $table->decimal('hours', 8, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'work_date']);
            $table->index(['task_id', 'user_id']);
        });

        Schema::create('project_file_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->string('disk')->default('s3');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'task_id']);
        });

        Schema::create('project_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['project_id', 'task_id']);
        });

        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->string('finance_reference')->nullable();
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->timestamps();

            $table->index(['project_id', 'expense_date']);
            $table->index('finance_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_expenses');
        Schema::dropIfExists('project_comments');
        Schema::dropIfExists('project_file_attachments');
        Schema::dropIfExists('project_time_logs');
        Schema::dropIfExists('project_tasks');
        Schema::dropIfExists('projects');
    }
};
