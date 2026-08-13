<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Close the NULL hole in the stock balance uniqueness constraint.
 *
 * `unique(product_id, warehouse_id, location_id)` does not constrain rows
 * whose `location_id` is NULL, because in SQL every NULL is distinct. Warehouse
 * level stock — the common case, and the one POS will use — is exactly those
 * rows. Two concurrent `firstOrCreate` calls therefore both insert, and the
 * product's quantity silently splits across two balances: one gets decremented,
 * the other keeps stock that availability checks will never find.
 *
 * A generated column collapses NULL to 0 so the unique index covers it.
 * Existing duplicates are merged first, because the index cannot be created
 * while they exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicateBalances();

        // VIRTUAL, not STORED. A stored generated column forces
        // ALGORITHM=COPY, and rebuilding this table fails while re-creating
        // its four foreign keys ("Cannot add foreign key constraint").
        // MySQL indexes virtual columns fine, and the value is trivial to
        // compute, so nothing is lost.
        DB::statement(
            'ALTER TABLE stock_balances
             ADD COLUMN location_key BIGINT UNSIGNED
             AS (COALESCE(location_id, 0)) VIRTUAL'
        );

        // Create the replacement before dropping the original: the outgoing
        // index is the only one covering product_id for its foreign key, and
        // MySQL refuses to leave an FK column unindexed.
        DB::statement(
            'CREATE UNIQUE INDEX stock_balance_location_unique
             ON stock_balances (product_id, warehouse_id, location_key)'
        );
        DB::statement('ALTER TABLE stock_balances DROP INDEX stock_balance_unique');
    }

    public function down(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX stock_balance_unique
             ON stock_balances (product_id, warehouse_id, location_id)'
        );
        DB::statement('ALTER TABLE stock_balances DROP INDEX stock_balance_location_unique');
        DB::statement('ALTER TABLE stock_balances DROP COLUMN location_key');
    }

    /**
     * Fold duplicate warehouse-level balances into their lowest-id row.
     *
     * Quantity is summed and the average cost recomputed as a weighted mean,
     * so the merged row carries the same total value the split rows did.
     */
    private function mergeDuplicateBalances(): void
    {
        $duplicates = DB::table('stock_balances')
            ->select('product_id', 'warehouse_id', DB::raw('COUNT(*) as row_count'))
            ->whereNull('location_id')
            ->groupBy('product_id', 'warehouse_id')
            ->having('row_count', '>', 1)
            ->get();

        foreach ($duplicates as $duplicate) {
            $rows = DB::table('stock_balances')
                ->where('product_id', $duplicate->product_id)
                ->where('warehouse_id', $duplicate->warehouse_id)
                ->whereNull('location_id')
                ->orderBy('id')
                ->get();

            $keep = $rows->first();
            $quantity = '0';
            $value = '0';

            foreach ($rows as $row) {
                $quantity = bcadd($quantity, (string) $row->quantity, 4);
                $value = bcadd($value, bcmul((string) $row->quantity, (string) $row->average_cost, 8), 8);
            }

            DB::table('stock_balances')->where('id', $keep->id)->update([
                'quantity' => $quantity,
                'average_cost' => bccomp($quantity, '0', 4) === 0 ? '0' : bcdiv($value, $quantity, 4),
                'updated_at' => now(),
            ]);

            DB::table('stock_balances')
                ->whereIn('id', $rows->skip(1)->pluck('id')->all())
                ->delete();
        }
    }
};
