<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tiktok_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('connection_method')->default('oauth');
            // Per-company app credentials, as with Meta.
            $table->string('app_id')->nullable();
            $table->text('app_secret')->nullable();
            $table->text('access_token')->nullable();
            // Unlike Meta, TikTok issues refresh tokens.
            $table->text('refresh_token')->nullable();
            $table->timestamp('access_token_expires_at')->nullable();
            $table->timestamp('refresh_token_expires_at')->nullable();
            $table->timestamp('token_refreshed_at')->nullable();
            $table->timestamp('token_expiry_notified_at')->nullable();
            $table->string('core_user_id')->nullable();
            $table->json('scopes')->nullable();
            $table->json('granted_scopes')->nullable();
            $table->timestamp('scopes_checked_at')->nullable();
            $table->string('default_advertiser_id')->nullable();
            $table->string('pixel_code')->nullable();
            $table->boolean('events_enabled')->default(false);
            $table->text('webhook_verify_token')->nullable();
            $table->char('webhook_verify_token_hash', 64)->nullable()->unique();
            $table->timestamp('last_health_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
            $table->index(['status', 'access_token_expires_at'], 'tiktok_connections_expiry_idx');
        });

        Schema::create('tiktok_advertisers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiktok_connection_id')->constrained('tiktok_connections')->cascadeOnDelete();
            $table->string('advertiser_id');
            $table->string('name')->nullable();
            $table->string('currency', 8)->nullable();
            $table->string('timezone')->nullable();
            $table->string('status')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['tiktok_connection_id', 'advertiser_id']);
            $table->index(['company_id', 'is_active']);
        });

        // Mirrors meta_event_mappings: a CRM/finance trigger to a TikTok event.
        Schema::create('tiktok_event_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiktok_connection_id')->constrained('tiktok_connections')->cascadeOnDelete();
            $table->string('trigger_source');
            $table->string('event_name');
            $table->boolean('is_active')->default(true);
            $table->string('value_field')->nullable();
            $table->string('currency_field')->nullable();
            $table->json('extra_params')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'trigger_source']);
        });

        Schema::create('tiktok_event_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tiktok_connection_id')->constrained('tiktok_connections')->cascadeOnDelete();
            $table->string('event_id');
            $table->string('event_name');
            $table->string('trigger_source')->nullable();
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'event_id']);
            $table->index(['status', 'next_retry_at']);
            $table->index(['related_type', 'related_id']);
        });

        // Lead attribution from TikTok, alongside the Meta columns.
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('tiktok_lead_id')->nullable()->after('meta_form_id');
            $table->string('tiktok_campaign_id')->nullable()->after('tiktok_lead_id');
            $table->string('tiktok_adgroup_id')->nullable()->after('tiktok_campaign_id');
            $table->string('tiktok_ad_id')->nullable()->after('tiktok_adgroup_id');

            $table->unique(['company_id', 'tiktok_lead_id'], 'crm_contacts_company_tiktok_lead_unique');
            $table->index(['company_id', 'tiktok_campaign_id'], 'crm_contacts_company_tiktok_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropUnique('crm_contacts_company_tiktok_lead_unique');
            $table->dropIndex('crm_contacts_company_tiktok_campaign_idx');
            $table->dropColumn(['tiktok_lead_id', 'tiktok_campaign_id', 'tiktok_adgroup_id', 'tiktok_ad_id']);
        });

        Schema::dropIfExists('tiktok_event_logs');
        Schema::dropIfExists('tiktok_event_mappings');
        Schema::dropIfExists('tiktok_advertisers');
        Schema::dropIfExists('tiktok_connections');
    }
};
