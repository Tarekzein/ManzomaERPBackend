<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Favorites pivot
        Schema::create('report_favorites', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('report_definition_id');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'report_definition_id']);
            $table->index('user_id');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('report_definition_id')->references('id')->on('report_definitions')->cascadeOnDelete();
        });

        // Share token on report definitions
        Schema::table('report_definitions', function (Blueprint $table) {
            $table->string('share_token', 64)->nullable()->unique()->after('is_shared');
        });

        // Alerts
        Schema::create('report_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('report_definition_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('name');
            $table->string('metric_field');
            $table->string('operator', 10);
            $table->decimal('threshold_value', 20, 4);
            $table->json('recipients');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'is_active']);
            $table->index(['report_definition_id', 'is_active']);
            $table->foreign('report_definition_id')->references('id')->on('report_definitions')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_alerts');
        Schema::table('report_definitions', function (Blueprint $table) {
            $table->dropColumn('share_token');
        });
        Schema::dropIfExists('report_favorites');
    }
};
