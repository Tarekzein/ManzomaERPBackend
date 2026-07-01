<?php

namespace App\Modules\Finance\Services;

use App\Modules\Finance\Models\JournalEntry;

class AccountingPostingService
{
    public function __construct(
        private readonly LedgerService $ledger,
        private readonly CompanyAccountResolver $accounts,
    ) {}

    public function postCogs(
        int $companyId,
        ?int $userId,
        string $entryDate,
        string $description,
        float $amount,
        string $currency,
        string $sourceType,
        int $sourceId,
    ): ?JournalEntry {
        if ($amount <= 0) {
            return null;
        }

        $cogs = $this->accounts->byCode($companyId, '5000', 'expense', 'Cost of Goods Sold account');
        $inventory = $this->accounts->byCode($companyId, '1200', 'asset', 'Inventory account');
        $entry = $this->ledger->createForCompany($companyId, $userId, [
            'entry_date' => $entryDate,
            'description' => $description,
            'lines' => [
                ['account_id' => $cogs->id, 'debit' => $amount, 'credit' => 0, 'currency' => $currency],
                ['account_id' => $inventory->id, 'debit' => 0, 'credit' => $amount, 'currency' => $currency],
            ],
        ], $sourceType, $sourceId);

        return $this->ledger->postSystem($entry, $userId);
    }

    public function postInventoryReceipt(
        int $companyId,
        ?int $userId,
        string $entryDate,
        string $description,
        float $amount,
        string $currency,
        string $sourceType,
        int $sourceId,
    ): ?JournalEntry {
        if ($amount <= 0) {
            return null;
        }

        $inventory = $this->accounts->byCode($companyId, '1200', 'asset', 'Inventory account');
        $clearing = $this->accounts->byCode($companyId, '2000', 'liability', 'Accounts Payable account');
        $entry = $this->ledger->createForCompany($companyId, $userId, [
            'entry_date' => $entryDate,
            'description' => $description,
            'lines' => [
                ['account_id' => $inventory->id, 'debit' => $amount, 'credit' => 0, 'currency' => $currency],
                ['account_id' => $clearing->id, 'debit' => 0, 'credit' => $amount, 'currency' => $currency],
            ],
        ], $sourceType, $sourceId);

        return $this->ledger->postSystem($entry, $userId);
    }
}
