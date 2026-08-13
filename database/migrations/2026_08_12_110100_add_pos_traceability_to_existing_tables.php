<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Additive links between POS and the modules that own its data.
 *
 * Everything here is nullable and backward compatible: existing Sales,
 * Inventory and document-numbering rows keep working untouched, and only rows
 * created by POS carry the new references.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_contacts', function (Blueprint $table) {
            // One customer identity across Sales, Finance and CRM. POS can sell
            // to a walk-in with no CRM record, so it stays optional.
            $table->foreignId('crm_contact_id')
                ->nullable()
                ->after('company_id')
                ->constrained('crm_contacts')
                ->nullOnDelete();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            // Which document caused this movement. `reference` already exists
            // but is free text; this is the resolvable link a POS sale, return
            // or void needs for traceability and reversal.
            $table->string('source_type')->nullable()->after('reference');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            $table->index(['source_type', 'source_id'], 'stock_movements_source_index');
        });

        Schema::table('document_sequences', function (Blueprint $table) {
            // Receipt numbers run per register, not per company: two tills
            // printing POS-2026-000001 on the same day is not acceptable.
            // The empty string is the company-wide scope. This must not be
            // nullable: MySQL permits multiple NULL values in a unique key.
            $table->string('scope', 40)->default('')->after('prefix');
        });

        // The existing unique key covers (company_id, prefix, period). Widen it
        // so a scoped counter is a separate row rather than a collision.
        // Create the replacement first: the outgoing index is the only one
        // covering company_id for its foreign key, and MySQL will not drop it
        // while that is true.
        Schema::table('document_sequences', function (Blueprint $table) {
            $table->unique(['company_id', 'prefix', 'scope', 'period'], 'document_sequence_scope_unique');
        });

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropUnique('document_sequences_company_id_prefix_period_unique');
        });
    }

    public function down(): void
    {
        // Collapse scoped counters before restoring the old company-wide
        // unique key. A rollback must remain executable after receipts exist.
        $groups = DB::table('document_sequences')
            ->select('company_id', 'prefix', 'period', DB::raw('COUNT(*) AS row_count'))
            ->groupBy('company_id', 'prefix', 'period')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('document_sequences')
                ->where('company_id', $group->company_id)
                ->where('prefix', $group->prefix)
                ->where('period', $group->period)
                ->orderBy('id')
                ->get();
            $keep = $rows->first();
            $nextValue = $rows->max('next_value');
            DB::table('document_sequences')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
            DB::table('document_sequences')->where('id', $keep->id)->update(['next_value' => $nextValue]);
        }

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->unique(['company_id', 'prefix', 'period'], 'document_sequences_company_id_prefix_period_unique');
        });

        Schema::table('document_sequences', function (Blueprint $table) {
            $table->dropUnique('document_sequence_scope_unique');
            $table->dropColumn('scope');
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropIndex('stock_movements_source_index');
            $table->dropColumn(['source_type', 'source_id']);
        });

        Schema::table('sales_contacts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('crm_contact_id');
        });
    }
};
