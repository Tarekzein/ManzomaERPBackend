<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_outbox_events', function (Blueprint $table) {
            $table->timestamp('failed_at')->nullable()->after('processed_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('pos_outbox_events', function (Blueprint $table) {
            $table->dropIndex(['failed_at']);
            $table->dropColumn('failed_at');
        });
    }
};
