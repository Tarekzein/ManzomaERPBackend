<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_audience_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiktok_connection_id')->constrained('tiktok_connections')->cascadeOnDelete();
            $table->foreignId('crm_segment_id')->constrained('crm_segments')->cascadeOnDelete();
            $table->string('advertiser_id');
            $table->string('tiktok_audience_id')->nullable();
            $table->string('audience_name');
            // TikTok matches on one identifier type per file.
            $table->string('calculate_type')->default('EMAIL_SHA256');
            $table->string('sync_mode')->default('scheduled');
            $table->string('schedule_frequency')->default('daily');
            $table->string('status')->default('pending');
            $table->unsignedInteger('approximate_count')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'crm_segment_id', 'tiktok_connection_id'], 'tiktok_audience_syncs_unique');
            $table->index('status');
            $table->index('schedule_frequency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_audience_syncs');
    }
};
