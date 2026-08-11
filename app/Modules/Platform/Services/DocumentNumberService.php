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
    public function next(int $companyId, string $prefix, ?string $period = null): string
    {
        $period ??= now()->format('Y');

        return DB::transaction(function () use ($companyId, $prefix, $period) {
            $sequence = $this->lockedSequence($companyId, $prefix, $period);
            $value = (int) $sequence->next_value;

            $sequence->forceFill(['next_value' => $value + 1])->save();

            return sprintf('%s-%s-%06d', $prefix, $period, $value);
        });
    }

    private function lockedSequence(int $companyId, string $prefix, string $period): DocumentSequence
    {
        $query = fn () => DocumentSequence::query()
            ->where('company_id', $companyId)
            ->where('prefix', $prefix)
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
                'period' => $period,
                // Existing installations already have documents from before
                // the sequence table was introduced. Start after their
                // highest new-format number instead of colliding at 000001.
                'next_value' => $this->initialValue($companyId, $prefix, $period),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another request created the counter first; take its lock instead.
            return $query() ?? throw new \RuntimeException("Could not obtain the {$prefix} sequence.");
        }
    }

    private function initialValue(int $companyId, string $prefix, string $period): int
    {
        $table = match ($prefix) {
            'JE' => 'journal_entries',
            'SM' => 'stock_movements',
            'SQ' => 'sales_quotations',
            'SO' => 'sales_orders',
            'PO' => 'purchase_orders',
            'GR' => 'goods_receipts',
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
}
