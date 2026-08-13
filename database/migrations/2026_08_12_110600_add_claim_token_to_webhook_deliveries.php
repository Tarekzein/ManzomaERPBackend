<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable()->after('status');
            $table->index(['status', 'next_attempt_at'], 'webhook_deliveries_retry_index');
        });
    }

    public function down(): void
    {
        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropIndex('webhook_deliveries_retry_index');
            $table->dropColumn('claim_token');
        });
    }
};
