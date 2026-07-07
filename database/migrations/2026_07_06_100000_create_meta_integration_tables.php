<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meta_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('connection_method')->default('oauth');
            $table->string('status')->default('pending');
            $table->text('access_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->string('business_id')->nullable();
            $table->string('ad_account_id')->nullable();
            $table->string('pixel_id')->nullable();
            $table->json('page_ids')->nullable();
            $table->string('default_page_id')->nullable();
            $table->json('scopes')->nullable();
            $table->string('test_event_code')->nullable();
            $table->text('webhook_verify_token')->nullable();
            $table->boolean('require_consent')->default(false);
            $table->boolean('ldu_enabled')->default(false);
            $table->unsignedSmallInteger('ldu_country')->nullable();
            $table->unsignedSmallInteger('ldu_state')->nullable();
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('status');
            $table->index('pixel_id');
        });

        Schema::create('meta_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('trigger_source');
            $table->string('meta_event_name');
            $table->boolean('is_active')->default(true);
            $table->string('value_field')->nullable();
            $table->string('currency_field')->nullable();
            $table->json('extra_params')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'trigger_source']);
        });

        Schema::create('meta_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('event_id');
            $table->string('event_name');
            $table->string('trigger_source')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->json('payload');
            $table->string('status')->default('pending');
            $table->smallInteger('http_status')->nullable();
            $table->json('response_body')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'event_id']);
            $table->index(['company_id', 'status']);
            $table->index(['related_type', 'related_id']);
            $table->index('next_retry_at');
        });

        Schema::create('meta_lead_form_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('page_id');
            $table->string('form_id');
            $table->string('form_name')->nullable();
            $table->json('field_mapping');
            $table->foreignId('default_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('default_source')->default('facebook_lead_ad');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'form_id']);
            $table->index('page_id');
        });

        Schema::create('meta_audience_syncs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->foreignId('crm_segment_id')->constrained('crm_segments')->cascadeOnDelete();
            $table->string('meta_audience_id')->nullable();
            $table->string('audience_name');
            $table->string('sync_mode')->default('scheduled');
            $table->string('schedule_frequency')->default('daily');
            $table->string('status')->default('pending');
            $table->unsignedInteger('approximate_count')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'crm_segment_id', 'meta_connection_id'], 'meta_audience_syncs_unique');
            $table->index('status');
            $table->index('schedule_frequency');
        });
    }

    public function down(): void
    {
        foreach ([
            'meta_audience_syncs',
            'meta_lead_form_mappings',
            'meta_event_logs',
            'meta_event_mappings',
            'meta_connections',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
