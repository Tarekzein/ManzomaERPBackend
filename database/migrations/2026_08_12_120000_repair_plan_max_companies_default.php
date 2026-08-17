<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `add_organization_billing_fields` introduced `max_companies` with a
     * blanket default of 1, so every plan that already existed was capped at a
     * single company regardless of what it sells. No administrator chose that
     * value, and the subscription seeder cannot correct it because it must
     * never overwrite live commercial data.
     *
     * This raises the catalogued plans back to their configured entitlement.
     * It only ever widens a limit: a plan already at or above its configured
     * value, or a slug that is not part of the shipped catalog, is left alone.
     */
    public function up(): void
    {
        foreach ((array) config('erp.plans') as $slug => $plan) {
            if (! array_key_exists('max_companies', $plan)) {
                continue;
            }

            $target = $plan['max_companies'];
            $query = DB::table('subscription_plans')->where('slug', $slug);

            if ($target === null) {
                // NULL means unlimited, which is wider than any integer cap.
                $query->whereNotNull('max_companies')->update(['max_companies' => null]);

                continue;
            }

            $query->whereNotNull('max_companies')
                ->where('max_companies', '<', (int) $target)
                ->update(['max_companies' => (int) $target]);
        }
    }

    public function down(): void
    {
        // Restoring an unchosen default would re-break the catalog.
    }
};
