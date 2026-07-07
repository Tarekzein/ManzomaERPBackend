<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->string('meta_fbc')->nullable()->after('custom_attributes');
            $table->string('meta_fbp')->nullable()->after('meta_fbc');
            $table->string('meta_lead_id')->nullable()->after('meta_fbp');
            $table->boolean('meta_consent')->nullable()->after('meta_lead_id');
            $table->timestamp('meta_last_synced_at')->nullable()->after('meta_consent');
            $table->index('meta_lead_id');
        });
    }

    public function down(): void
    {
        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropIndex(['meta_lead_id']);
            $table->dropColumn(['meta_fbc', 'meta_fbp', 'meta_lead_id', 'meta_consent', 'meta_last_synced_at']);
        });
    }
};
