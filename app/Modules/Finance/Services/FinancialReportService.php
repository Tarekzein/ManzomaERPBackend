<?php

namespace App\Modules\Finance\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalLine;
use App\Modules\Finance\Policies\FinancePolicy;

class FinancialReportService
{
    public function __construct(private readonly FinancePolicy $policy) {}

    public function trialBalance(User $user, ?string $from = null, ?string $to = null): array
    {
        $companyId = $this->policy->companyId($user);

        return Account::where('company_id', $companyId)->orderBy('code')->get()->map(function ($a) use ($from, $to) {
            $q = JournalLine::where('account_id', $a->id)->whereHas('journalEntry', fn ($e) => $e->where('status', 'posted'));
            if ($from) {
                $q->whereHas('journalEntry', fn ($e) => $e->whereDate('entry_date', '>=', $from));
            } if ($to) {
                $q->whereHas('journalEntry', fn ($e) => $e->whereDate('entry_date', '<=', $to));
            }
            $debit = (float) $q->sum('base_debit');
            $credit = (float) $q->sum('base_credit');

            return ['account_id' => $a->id, 'code' => $a->code, 'name' => $a->name, 'type' => $a->type, 'debit' => $debit, 'credit' => $credit, 'balance' => $debit - $credit];
        })->all();
    }

    public function statement(User $user, string $type, ?string $from = null, ?string $to = null): array
    {
        $trial = collect($this->trialBalance($user, $from, $to));

        return match ($type) {
            'balance-sheet' => ['assets' => $trial->where('type', 'asset')->values(), 'liabilities' => $trial->where('type', 'liability')->values(), 'equity' => $trial->where('type', 'equity')->values()],
            'profit-loss' => ['revenue' => $trial->where('type', 'revenue')->values(), 'expenses' => $trial->where('type', 'expense')->values(), 'net_income' => $trial->where('type', 'revenue')->sum('credit') - $trial->where('type', 'expense')->sum('debit')],
            'cash-flow' => ['cash_accounts' => $trial->filter(fn ($a) => $a['type'] === 'asset' && str_contains(strtolower($a['name']), 'cash'))->values()],
            default => ['accounts' => $trial],
        };
    }

    public function variance(User $user, Budget $budget): array
    {
        $this->policy->ensureOwned($user, $budget);
        $actual = collect($this->trialBalance($user, $budget->starts_on->toDateString(), $budget->ends_on->toDateString()))->keyBy('account_id');

        return $budget->load('lines.account')->lines->map(fn ($l) => ['account' => $l->account, 'budget' => (float) $l->amount, 'actual' => (float) ($actual[$l->account_id]['balance'] ?? 0), 'variance' => (float) $l->amount - (float) ($actual[$l->account_id]['balance'] ?? 0)])->all();
    }

    public function aging(User $user, string $type): array
    {
        $companyId = $this->policy->companyId($user);

        return Invoice::with('contact')->where('company_id', $companyId)->where('type', $type)
            ->whereIn('status', ['posted', 'partially_paid'])->get()->map(function (Invoice $invoice) {
                $days = max(0, now()->startOfDay()->diffInDays($invoice->due_date, false) * -1);
                $bucket = match (true) {
                    $days === 0 => 'current', $days <= 30 => '1-30', $days <= 60 => '31-60',
                    $days <= 90 => '61-90', default => '90+',
                };

                return ['invoice' => $invoice, 'outstanding' => (float) $invoice->total - (float) $invoice->paid_total, 'days_overdue' => $days, 'bucket' => $bucket];
            })->all();
    }
}
