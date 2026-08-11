<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_lead_form_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiktok_connection_id')->constrained('tiktok_connections')->cascadeOnDelete();
            $table->string('advertiser_id');
            // TikTok calls a lead form an "instant page".
            $table->string('page_id');
            $table->string('page_name')->nullable();
            $table->json('field_mapping');
            $table->foreignId('default_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('default_source')->nullable();
            $table->boolean('is_active')->default(true);
            // Leads arrive through an asynchronous export task, so the current
            // task and where the last import got to are tracked here.
            $table->string('current_task_id')->nullable();
            $table->string('task_status')->nullable();
            $table->timestamp('task_requested_at')->nullable();
            $table->timestamp('synced_through')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tiktok_connection_id', 'page_id']);
            $table->index(['company_id', 'is_active']);
            $table->index('current_task_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tiktok_lead_form_mappings');
    }
};
