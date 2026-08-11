<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Meta retries a lead webhook until it gets a 2xx, and the ingest was a
        // check-then-insert, so concurrent deliveries could both pass the check
        // and create two contacts for one lead.
        $this->removeDuplicateLeads();

        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropIndex(['meta_lead_id']);
            $table->unique(['company_id', 'meta_lead_id'], 'crm_contacts_company_meta_lead_unique');
        });

        Schema::table('meta_connections', function (Blueprint $table) {
            // The verify token is encrypted (non-deterministic), so matching an
            // incoming webhook meant loading and decrypting every connection on
            // an unauthenticated endpoint. This lets us look it up by hash.
            $table->char('webhook_verify_token_hash', 64)->nullable()->unique()->after('webhook_verify_token');
            $table->timestamp('token_refreshed_at')->nullable()->after('access_token_expires_at');
            $table->index(['status', 'access_token_expires_at'], 'meta_connections_expiry_idx');
        });

        $this->backfillVerifyTokenHashes();
    }

    public function down(): void
    {
        Schema::table('meta_connections', function (Blueprint $table) {
            $table->dropIndex('meta_connections_expiry_idx');
            $table->dropUnique(['webhook_verify_token_hash']);
            $table->dropColumn(['webhook_verify_token_hash', 'token_refreshed_at']);
        });

        Schema::table('crm_contacts', function (Blueprint $table) {
            $table->dropUnique('crm_contacts_company_meta_lead_unique');
            $table->index('meta_lead_id');
        });
    }

    /** Keep the earliest contact per (company, lead) and unlink the rest. */
    private function removeDuplicateLeads(): void
    {
        $duplicates = DB::table('crm_contacts')
            ->select('company_id', 'meta_lead_id', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('meta_lead_id')
            ->groupBy('company_id', 'meta_lead_id')
            ->having('total', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            DB::table('crm_contacts')
                ->where('company_id', $duplicate->company_id)
                ->where('meta_lead_id', $duplicate->meta_lead_id)
                ->where('id', '!=', $duplicate->keep_id)
                ->update(['meta_lead_id' => null]);
        }
    }

    private function backfillVerifyTokenHashes(): void
    {
        DB::table('meta_connections')
            ->whereNotNull('webhook_verify_token')
            ->orderBy('id')
            ->chunkById(100, function ($connections) {
                foreach ($connections as $connection) {
                    try {
                        $token = decrypt($connection->webhook_verify_token);
                    } catch (Throwable) {
                        continue; // Legacy plaintext or unreadable value: leave it.
                    }

                    DB::table('meta_connections')
                        ->where('id', $connection->id)
                        ->update(['webhook_verify_token_hash' => hash('sha256', $token)]);
                }
            });
    }
};
