<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Soft deletes for contacts and opportunities
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->softDeletes()->after('converted_at');
            $table->unsignedTinyInteger('lead_score')->default(0)->after('deleted_at');
            $table->timestamp('score_computed_at')->nullable()->after('lead_score');
            $table->index(['company_id', 'lead_score']);
        });

        Schema::table('crm_opportunities', function (Blueprint $table) {
            $table->softDeletes()->after('lost_at');
        });

        // CRM Notes
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contact_id')->nullable();
            $table->unsignedBigInteger('opportunity_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->text('body');
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();
            $table->index(['contact_id', 'is_pinned']);
            $table->index(['opportunity_id', 'is_pinned']);
            $table->index(['company_id', 'created_at']);
            $table->foreign('contact_id')->references('id')->on('crm_contacts')->cascadeOnDelete();
            $table->foreign('opportunity_id')->references('id')->on('crm_opportunities')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn(['lead_score', 'score_computed_at']);
        });
        Schema::table('crm_opportunities', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
