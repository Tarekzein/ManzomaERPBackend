<?php

namespace App\Modules\Platform\Services;

use App\Modules\Platform\Models\DocumentSequence;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Sequential document numbers, one counter per company, prefix and period.
 *
 * Numbering has to be both unique and sequential: unique because every document
 * table carries a `(company_id, number)` unique index, and sequential because
 * invoices and journal entries are audited. A counter read under `lockForUpdate`
 * gives both — concurrent callers queue rather than produce the same number.
 *
 * The lock is held until the surrounding transaction commits, which is what
 * makes it correct when a document and its number are written together.
 */
class DocumentNumberService
{
    public function next(int $companyId, string $prefix, ?string $period = null, ?string $scope = null): string
    {
        $period ??= now()->format('Y');
        // An empty string is the canonical company-wide scope. Using NULL in
        // a MySQL unique key would allow duplicate counter rows under load.
        $scope ??= '';

        return DB::transaction(function () use ($companyId, $prefix, $period, $scope) {
            $sequence = $this->lockedSequence($companyId, $prefix, $period, $scope);
            $value = (int) $sequence->next_value;

            $sequence->forceFill(['next_value' => $value + 1])->save();

            return $this->stem($prefix, $period, $scope).sprintf('%06d', $value);
        });
    }

    private function lockedSequence(int $companyId, string $prefix, string $period, string $scope): DocumentSequence
    {
        $query = fn () => DocumentSequence::query()
            ->where('company_id', $companyId)
            ->where('prefix', $prefix)
            ->where('scope', $scope)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if ($sequence = $query()) {
            return $sequence;
        }

        try {
            return DocumentSequence::create([
                'company_id' => $companyId,
                'prefix' => $prefix,
                'scope' => $scope,
                'period' => $period,
                // Existing installations already have documents from before
                // the sequence table was introduced. Start after their
                // highest new-format number instead of colliding at 000001.
                'next_value' => $this->initialValue($companyId, $prefix, $period, $scope),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request created the counter first; take its lock instead.
            return $query() ?? throw new \RuntimeException("Could not obtain the {$prefix} sequence.");
        }
    }

    private function initialValue(int $companyId, string $prefix, string $period, string $scope): int
    {
        if (str_starts_with($scope, 'pos-register:')) {
            return $this->initialPosValue($companyId, $prefix, $period, (int) substr($scope, 13));
        }

        $table = match ($prefix) {
            'JE' => 'journal_entries',
            'SM' => 'stock_movements',
            'SQ' => 'sales_quotations',
            'SO' => 'sales_orders',
            'PO' => 'purchase_orders',
            'GR' => 'goods_receipts',
            'INV' => 'invoices',
            'CN' => 'credit_notes',
            default => null,
        };

        if (! $table) {
            return 1;
        }

        $stem = "{$prefix}-{$period}-";
        $highest = DB::table($table)
            ->where('company_id', $companyId)
            ->where('number', 'like', $stem.'%')
            ->max('number');

        if (! is_string($highest) || ! preg_match('/'.preg_quote($stem, '/').'(\d+)$/', $highest, $matches)) {
            return 1;
        }

        return ((int) $matches[1]) + 1;
    }

    /** Continue safely when a deployment already printed receipts pre-scope. */
    private function initialPosValue(int $companyId, string $prefix, string $period, int $registerId): int
    {
        $table = str_ends_with($prefix, '-RTN') ? 'pos_returns' : 'pos_sales';
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return 1;
        }

        $newStem = $this->stem($prefix, $period, 'pos-register:'.$registerId);
        $legacyStem = "{$prefix}-{$period}-";
        $highest = DB::table($table)
            ->where('company_id', $companyId)
            ->where('pos_register_id', $registerId)
            ->where(fn ($query) => $query
                ->where('receipt_number', 'like', $newStem.'%')
                ->orWhere('receipt_number', 'like', $legacyStem.'%'))
            ->max('receipt_number');

        if (! is_string($highest) || ! preg_match('/(?:'.preg_quote($newStem, '/').'|'.preg_quote($legacyStem, '/').')(\d+)$/', $highest, $matches)) {
            return 1;
        }

        return ((int) $matches[1]) + 1;
    }

    /**
     * A scoped counter must also have a scoped printed identity. Otherwise two
     * registers sharing a receipt prefix would independently emit the same
     * human-facing number even though their counter rows were separate.
     */
    private function stem(string $prefix, string $period, string $scope): string
    {
        if ($scope === '') {
            return "{$prefix}-{$period}-";
        }

        $label = str_starts_with($scope, 'pos-register:')
            ? 'R'.(int) substr($scope, 13)
            : strtoupper(substr(hash('sha256', $scope), 0, 8));

        return "{$prefix}-{$label}-{$period}-";
    }
}
