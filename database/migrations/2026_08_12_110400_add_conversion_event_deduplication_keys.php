<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One business event must create at most one platform conversion log.
 *
 * Existing rows remain nullable for a non-disruptive deployment. Services
 * attach a key lazily when an older event is seen again; every newly recorded
 * event receives one immediately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meta_event_logs', function (Blueprint $table) {
            $table->char('deduplication_key', 64)->nullable()->after('event_id');
            $table->unique('deduplication_key', 'meta_event_logs_dedupe_unique');
        });

        Schema::table('tiktok_event_logs', function (Blueprint $table) {
            $table->char('deduplication_key', 64)->nullable()->after('event_id');
            $table->unique('deduplication_key', 'tiktok_event_logs_dedupe_unique');
        });
    }

    public function down(): void
    {
        Schema::table('tiktok_event_logs', function (Blueprint $table) {
            $table->dropUnique('tiktok_event_logs_dedupe_unique');
            $table->dropColumn('deduplication_key');
        });

        Schema::table('meta_event_logs', function (Blueprint $table) {
            $table->dropUnique('meta_event_logs_dedupe_unique');
            $table->dropColumn('deduplication_key');
        });
    }
};
