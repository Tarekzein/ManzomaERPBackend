<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            // What Meta actually granted, as opposed to what we asked for.
            $table->json('granted_scopes')->nullable()->after('scopes');
            $table->json('declined_scopes')->nullable()->after('granted_scopes');
            $table->timestamp('scopes_checked_at')->nullable()->after('declined_scopes');
            // "user" tokens expire after ~60 days; "system_user" tokens do not.
            $table->string('token_type')->default('user')->after('access_token_expires_at');
            $table->timestamp('token_expiry_notified_at')->nullable()->after('token_refreshed_at');
            $table->timestamp('disconnected_at')->nullable()->after('last_error');
        });

        // Pages and their linked Instagram Business accounts. Page tokens live
        // here, encrypted, and never leave the server.
        Schema::create('meta_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meta_connection_id')->constrained('meta_connections')->cascadeOnDelete();
            $table->string('page_id');
            $table->string('name')->nullable();
            $table->string('category')->nullable();
            $table->text('access_token')->nullable();
            $table->json('tasks')->nullable();
            $table->string('instagram_account_id')->nullable();
            $table->string('instagram_username')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('webhook_subscribed_at')->nullable();
            $table->json('webhook_fields')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['meta_connection_id', 'page_id']);
            $table->index(['company_id', 'is_active']);
            $table->index('instagram_account_id');
        });

        // Campaign attribution for leads arriving from Meta.
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('meta_campaign_id')->nullable()->after('meta_lead_id');
            $table->string('meta_adset_id')->nullable()->after('meta_campaign_id');
            $table->string('meta_ad_id')->nullable()->after('meta_adset_id');
            $table->string('meta_platform')->nullable()->after('meta_ad_id');
            $table->string('meta_form_id')->nullable()->after('meta_platform');

            $table->index(['company_id', 'meta_campaign_id'], 'crm_contacts_company_campaign_idx');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropIndex('crm_contacts_company_campaign_idx');
            $table->dropColumn(['meta_campaign_id', 'meta_adset_id', 'meta_ad_id', 'meta_platform', 'meta_form_id']);
        });

        Schema::dropIfExists('meta_pages');

        Schema::table('meta_connections', function (Blueprint $table) {
            $table->dropColumn([
                'granted_scopes', 'declined_scopes', 'scopes_checked_at',
                'token_type', 'token_expiry_notified_at', 'disconnected_at',
            ]);
        });
    }
};
