<?php

namespace App\Modules\Finance\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Contracts\FinanceRepository;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialPeriod;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Finance\Policies\FinancePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerService
{
    public function __construct(private readonly FinanceRepository $finance, private readonly FinancePolicy $policy) {}

    public function accounts(User $actor)
    {
        return $this->finance->list(Account::class, $this->policy->companyId($actor));
    }

    public function periods(User $actor)
    {
        return $this->finance->list(FinancialPeriod::class, $this->policy->companyId($actor));
    }

    public function journals(User $actor)
    {
        return $this->finance->list(JournalEntry::class, $this->policy->companyId($actor), ['lines.account', 'period']);
    }

    public function createAccount(User $actor, array $data): Account
    {
        $companyId = $this->policy->companyId($actor, 'finance.create');
        $this->ensureRelatedAccount($companyId, $data['parent_id'] ?? null);

        return Account::create(['company_id' => $companyId] + $data);
    }

    public function updateAccount(User $actor, Account $account, array $data): Account
    {
        $companyId = $this->policy->ensureOwned($actor, $account);
        $this->ensureRelatedAccount($companyId, $data['parent_id'] ?? null);
        $account->update($data);

        return $account->refresh();
    }

    public function createPeriod(User $actor, array $data): FinancialPeriod
    {
        $companyId = $this->policy->companyId($actor, 'finance.create');
        $overlap = FinancialPeriod::where('company_id', $companyId)->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['starts_on' => ['Financial periods cannot overlap.']]);
        }

        return FinancialPeriod::create(['company_id' => $companyId] + $data);
    }

    public function lockPeriod(User $actor, FinancialPeriod $period): FinancialPeriod
    {
        $this->policy->ensureOwned($actor, $period);
        $period->update(['is_locked' => true, 'locked_at' => now(), 'locked_by' => $actor->id]);

        return $period->refresh();
    }

    public function createJournal(User $actor, array $data, string $sourceType = 'manual', ?int $sourceId = null): JournalEntry
    {
        $companyId = $this->policy->companyId($actor, 'finance.create');

        return $this->createForCompany($companyId, $actor->id, $data, $sourceType, $sourceId, true);
    }

    public function createForCompany(int $companyId, ?int $userId, array $data, string $sourceType, ?int $sourceId, bool $manual = false): JournalEntry
    {
        $period = $this->finance->openPeriod($companyId, $data['entry_date']);
        $this->validateLines($companyId, $data['lines'], $manual);

        return DB::transaction(function () use ($companyId, $userId, $data, $sourceType, $sourceId, $period) {
            $entry = JournalEntry::create([
                'company_id' => $companyId, 'financial_period_id' => $period->id, 'number' => $this->finance->nextNumber($companyId, 'JE'),
                'entry_date' => $data['entry_date'], 'description' => $data['description'], 'status' => 'draft',
                'source_type' => $sourceType, 'source_id' => $sourceId, 'created_by' => $userId,
            ]);
            foreach ($data['lines'] as $line) {
                $rate = (float) ($line['exchange_rate'] ?? 1);
                $entry->lines()->create([
                    'account_id' => $line['account_id'], 'description' => $line['description'] ?? null,
                    'debit' => $line['debit'] ?? 0, 'credit' => $line['credit'] ?? 0, 'currency' => $line['currency'] ?? 'EGP', 'exchange_rate' => $rate,
                    'base_debit' => round(((float) ($line['debit'] ?? 0)) * $rate, 4), 'base_credit' => round(((float) ($line['credit'] ?? 0)) * $rate, 4),
                ]);
            }

            return $entry->load('lines.account', 'period');
        });
    }

    public function post(User $actor, JournalEntry $entry): JournalEntry
    {
        $this->policy->ensureOwned($actor, $entry);
        if ($entry->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft journal entries can be posted.']]);
        }
        $this->finance->openPeriod($entry->company_id, $entry->entry_date->toDateString());
        $entry->update(['status' => 'posted', 'posted_by' => $actor->id, 'posted_at' => now()]);

        return $entry->load('lines.account', 'period');
    }

    public function postSystem(JournalEntry $entry, ?int $userId): JournalEntry
    {
        $entry->update(['status' => 'posted', 'posted_by' => $userId, 'posted_at' => now()]);

        return $entry;
    }

    private function validateLines(int $companyId, array $lines, bool $manual): void
    {
        $ids = collect($lines)->pluck('account_id')->unique();
        $accounts = Account::where('company_id', $companyId)->whereIn('id', $ids)->get();
        if ($accounts->count() !== $ids->count()) {
            throw ValidationException::withMessages(['lines' => ['Every journal account must belong to the company.']]);
        }
        if ($manual && $accounts->contains(fn (Account $account) => ! $account->allow_manual_entries)) {
            throw ValidationException::withMessages(['lines' => ['System control accounts cannot be used in manual journal entries.']]);
        }
        $debits = collect($lines)->sum(fn ($l) => (float) ($l['debit'] ?? 0) * (float) ($l['exchange_rate'] ?? 1));
        $credits = collect($lines)->sum(fn ($l) => (float) ($l['credit'] ?? 0) * (float) ($l['exchange_rate'] ?? 1));
        if ($debits <= 0 || abs($debits - $credits) > 0.005) {
            throw ValidationException::withMessages(['lines' => ['Journal entries must have equal non-zero debits and credits in base currency.']]);
        }
        foreach ($lines as $line) {
            if (($line['debit'] ?? 0) > 0 && ($line['credit'] ?? 0) > 0) {
                throw ValidationException::withMessages(['lines' => ['A journal line cannot contain both debit and credit.']]);
            }
        }
    }

    private function ensureRelatedAccount(int $companyId, ?int $accountId): void
    {
        if ($accountId && ! Account::where('company_id', $companyId)->whereKey($accountId)->exists()) {
            throw ValidationException::withMessages(['parent_id' => ['The account must belong to the company.']]);
        }
    }
}
