<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repair installations that ran the first POS traceability migration while
 * document_sequences.scope was nullable. MySQL considers every NULL distinct
 * inside a unique index, so concurrent company-wide numbering could otherwise
 * create more than one counter for the same prefix and period.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('document_sequences', 'scope')) {
            return;
        }

        $duplicates = DB::table('document_sequences')
            ->select('company_id', 'prefix', 'period', DB::raw('COUNT(*) AS row_count'))
            ->where(fn ($query) => $query->whereNull('scope')->orWhere('scope', ''))
            ->groupBy('company_id', 'prefix', 'period')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('document_sequences')
                ->where('company_id', $duplicate->company_id)
                ->where('prefix', $duplicate->prefix)
                ->where('period', $duplicate->period)
                ->where(fn ($query) => $query->whereNull('scope')->orWhere('scope', ''))
                ->orderBy('id')
                ->get();

            $keep = $rows->first();
            $nextValue = $rows->max('next_value');
            DB::table('document_sequences')->whereIn('id', $rows->skip(1)->pluck('id'))->delete();
            DB::table('document_sequences')->where('id', $keep->id)->update([
                'next_value' => $nextValue,
                'scope' => '',
                'updated_at' => now(),
            ]);
        }

        DB::table('document_sequences')->whereNull('scope')->update(['scope' => '']);
        Schema::table('document_sequences', function (Blueprint $table): void {
            $table->string('scope', 40)->default('')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('document_sequences', 'scope')) {
            Schema::table('document_sequences', function (Blueprint $table): void {
                $table->string('scope', 40)->nullable()->default(null)->change();
            });
        }
    }
};
