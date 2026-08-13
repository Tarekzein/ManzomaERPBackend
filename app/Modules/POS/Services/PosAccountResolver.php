<?php

namespace App\Modules\POS\Services;

use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Services\CompanyAccountResolver;

/**
 * Accounts POS needs that a default chart does not ship with.
 *
 * Card and wallet takings are not cash: they sit with the acquirer until it
 * settles, sometimes for days. Posting them straight to the drawer would make
 * every shift reconciliation wrong, so POS provisions a clearing account the
 * first time a company takes a non-cash tender.
 */
class PosAccountResolver
{
    private const CLEARING_CODE = '1050';

    public function __construct(private readonly CompanyAccountResolver $accounts) {}

    public function cash(int $companyId): Account
    {
        return $this->accounts->byCode($companyId, '1000', 'asset', 'Cash on hand');
    }

    public function receivable(int $companyId): Account
    {
        return $this->accounts->byCode($companyId, '1100', 'asset', 'Accounts receivable');
    }

    /** Created on demand, because most charts have no such account yet. */
    public function clearing(int $companyId, ?string $currency = null): Account
    {
        $account = Account::query()->firstOrCreate(
            ['company_id' => $companyId, 'code' => self::CLEARING_CODE],
            [
                'name' => 'Card and Wallet Clearing',
                'type' => 'asset',
                'subtype' => 'cash',
                'currency' => $currency ?? 'EGP',
                'is_active' => true,
                'allow_manual_entries' => false,
            ],
        );

        return $this->accounts->ensure($companyId, (int) $account->getKey(), 'asset', 'POS clearing account');
    }

    /** An explicitly mapped account wins; otherwise fall back by tender kind. */
    public function forTender(int $companyId, ?int $accountId, bool $settlesToDrawer, ?string $currency = null): Account
    {
        if ($accountId) {
            return $this->accounts->ensure($companyId, $accountId, 'asset', 'POS tender account');
        }

        return $settlesToDrawer ? $this->cash($companyId) : $this->clearing($companyId, $currency);
    }
}
