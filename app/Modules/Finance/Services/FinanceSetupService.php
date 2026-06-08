<?php

namespace App\Modules\Finance\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialPeriod;

class FinanceSetupService
{
    public function provision(Company $company): void
    {
        $accounts = [
            ['code' => '1000', 'name' => 'Cash and Cash Equivalents', 'type' => 'asset', 'subtype' => 'cash'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'subtype' => 'receivable', 'allow_manual_entries' => false],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'inventory'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'payable', 'allow_manual_entries' => false],
            ['code' => '2100', 'name' => 'Tax Payable', 'type' => 'liability', 'subtype' => 'tax'],
            ['code' => '3000', 'name' => 'Owner Equity', 'type' => 'equity', 'subtype' => 'equity'],
            ['code' => '4000', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'sales'],
            ['code' => '5000', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'subtype' => 'cost_of_sales'],
            ['code' => '6000', 'name' => 'Operating Expenses', 'type' => 'expense', 'subtype' => 'operating'],
        ];
        foreach ($accounts as $account) {
            Account::updateOrCreate(['company_id' => $company->id, 'code' => $account['code']], $account + ['currency' => $company->currency, 'is_active' => true, 'allow_manual_entries' => $account['allow_manual_entries'] ?? true]);
        }
        FinancialPeriod::firstOrCreate(['company_id' => $company->id, 'name' => now()->format('Y')], ['starts_on' => now()->startOfYear(), 'ends_on' => now()->endOfYear(), 'is_locked' => false]);
    }
}
