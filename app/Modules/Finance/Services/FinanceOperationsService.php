<?php

namespace App\Modules\Finance\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\BankTransaction;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\ExchangeRate;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Models\TaxRate;
use App\Modules\Finance\Policies\FinancePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class FinanceOperationsService
{
    public function __construct(private readonly FinancePolicy $policy)
    {
    }

    public function taxes(User $u)
    {
        return TaxRate::where('company_id', $this->policy->companyId($u))->get();
    }

    public function rates(User $u)
    {
        return ExchangeRate::where('company_id', $this->policy->companyId($u))->latest('rate_date')->get();
    }

    public function banks(User $u)
    {
        return BankAccount::with('transactions')->where('company_id', $this->policy->companyId($u))->get();
    }

    public function budgets(User $u)
    {
        return Budget::with('lines.account')->where('company_id', $this->policy->companyId($u))->get();
    }

    public function createTax(User $u, array $d)
    {
        return TaxRate::create(['company_id' => $this->policy->companyId($u, 'finance.create')] + $d);
    }

    public function createRate(User $u, array $d)
    {
        return ExchangeRate::updateOrCreate(['company_id' => $this->policy->companyId($u, 'finance.create'), 'base_currency' => $d['base_currency'], 'quote_currency' => $d['quote_currency'], 'rate_date' => $d['rate_date']], $d);
    }

    public function createBank(User $u, array $d)
    {
        $companyId = $this->policy->companyId($u, 'finance.create');
        Account::where('company_id', $companyId)->findOrFail($d['account_id']);

        return BankAccount::create(['company_id' => $companyId] + $d);
    }

    public function createTransaction(User $u, array $d)
    {
        $companyId = $this->policy->companyId($u, 'finance.create');
        BankAccount::where('company_id', $companyId)->findOrFail($d['bank_account_id']);

        return BankTransaction::create(['company_id' => $companyId] + $d);
    }

    public function reconcile(User $u, BankTransaction $transaction, array $d): BankTransaction
    {
        $companyId = $this->policy->ensureOwned($u, $transaction);
        $entry = JournalEntry::where('company_id', $companyId)->where('status', 'posted')->findOrFail($d['journal_entry_id']);
        if (abs((float) $transaction->amount - (float) $entry->lines()->where('account_id', $transaction->bankAccount?->account_id)->selectRaw('SUM(base_debit-base_credit) balance')->value('balance')) > 0.01) {
            throw ValidationException::withMessages(['journal_entry_id' => ['Bank transaction amount does not match the selected journal entry.']]);
        }
        $transaction->update(['journal_entry_id' => $entry->id, 'is_reconciled' => true, 'reconciled_at' => now()]);

        return $transaction->refresh();
    }

    public function createBudget(User $u, array $d): Budget
    {
        $companyId = $this->policy->companyId($u, 'finance.create');
        $accountIds = collect($d['lines'])->pluck('account_id')->unique();
        if (Account::where('company_id', $companyId)->whereIn('id', $accountIds)->count() !== $accountIds->count()) {
            throw ValidationException::withMessages(['lines' => ['Every budget account must belong to the company.']]);
        }

        return DB::transaction(function () use ($companyId, $d) {
            $budget = Budget::create(['company_id' => $companyId] + collect($d)->except('lines')->all());
            $budget->lines()->createMany($d['lines']);

            return $budget->load('lines.account');
        });
    }

    public function syncRates(User $u, array $quotes): array
    {
        $companyId = $this->policy->companyId($u, 'finance.edit');
        $base = $u->company->currency;
        $key = config('services.open_exchange_rates.app_id');
        if (!$key) {
            throw ValidationException::withMessages(['exchange_rates' => ['OPEN_EXCHANGE_RATES_APP_ID is not configured.']]);
        }
        $response = Http::timeout(10)->get('https://api.exchangeratesapi.io/v1/latest', ['access_key' => $key])->throw()->json();
        
        $apiBase = $response['base'] ?? 'EUR';
        $quotesToSave = array_unique(array_merge($quotes, [$base, $apiBase]));
        
        $savedRates = [];
        foreach ($response['rates'] ?? [] as $quote => $rate) {
            if (in_array($quote, $quotesToSave)) {
                ExchangeRate::updateOrCreate([
                    'company_id' => $companyId, 
                    'base_currency' => $apiBase, 
                    'quote_currency' => $quote, 
                    'rate_date' => now()->toDateString()
                ], [
                    'rate' => $rate, 
                    'source' => 'open_exchange_rates'
                ]);
                $savedRates[$quote] = $rate;
            }
        }

        return $savedRates;
    }
}
