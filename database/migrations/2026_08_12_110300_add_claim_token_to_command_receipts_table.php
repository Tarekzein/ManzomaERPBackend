<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Fence each owner of an in-progress idempotency claim.
 *
 * A stale claim may be taken over while its original worker is still alive.
 * The token identifies the current ownership epoch, so that original worker
 * can no longer complete or delete the replacement worker's reservation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('command_receipts', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable()->after('request_hash');
        });

        DB::table('command_receipts')
            ->select('id')
            ->orderBy('id')
            ->chunkById(500, function ($receipts): void {
                foreach ($receipts as $receipt) {
                    DB::table('command_receipts')
                        ->where('id', $receipt->id)
                        ->whereNull('claim_token')
                        ->update(['claim_token' => (string) Str::uuid()]);
                }
            });

        Schema::table('command_receipts', function (Blueprint $table) {
            $table->uuid('claim_token')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('command_receipts', function (Blueprint $table) {
            $table->dropColumn('claim_token');
        });
    }
};
