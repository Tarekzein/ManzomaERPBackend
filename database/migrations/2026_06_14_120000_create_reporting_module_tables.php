<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('source');
            $table->json('fields');
            $table->json('filters')->nullable();
            $table->json('groupings')->nullable();
            $table->json('metrics')->nullable();
            $table->string('chart_type')->default('table');
            $table->boolean('is_shared')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'source']);
        });

        Schema::create('report_dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('source');
            $table->string('chart_type')->default('number');
            $table->json('configuration');
            $table->unsignedInteger('position')->default(0);
            $table->unsignedTinyInteger('width')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'user_id', 'position'], 'report_widget_position_index');
        });

        Schema::create('report_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('frequency')->default('daily');
            $table->string('format')->default('csv');
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'next_run_at']);
        });

        Schema::create('report_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_definition_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('schedule_id')->nullable()->constrained('report_schedules')->nullOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('completed');
            $table->string('format')->default('json');
            $table->unsignedInteger('row_count')->default(0);
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_runs');
        Schema::dropIfExists('report_schedules');
        Schema::dropIfExists('report_dashboard_widgets');
        Schema::dropIfExists('report_definitions');
    }
};
