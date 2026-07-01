<?php

namespace App\Modules\Finance\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSchedule;
use App\Modules\Finance\Models\TaxRate;
use App\Modules\Finance\Policies\FinancePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(
        private readonly FinancePolicy $policy,
        private readonly LedgerService $ledger,
        private readonly CompanyAccountResolver $accounts,
    ) {}

    public function contacts(User $actor)
    {
        return FinanceContact::where('company_id', $this->policy->companyId($actor))->latest()->get();
    }

    public function invoices(User $actor, ?string $type = null)
    {
        return Invoice::with('contact', 'lines.account', 'lines.taxRate', 'payments.allocations', 'journalEntry')
            ->where('company_id', $this->policy->companyId($actor))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->latest()
            ->get();
    }

    public function schedules(User $actor)
    {
        return PaymentSchedule::with('invoice.contact')->where('company_id', $this->policy->companyId($actor))->orderBy('scheduled_for')->get();
    }

    public function createContact(User $actor, array $data): FinanceContact
    {
        return FinanceContact::create(['company_id' => $this->policy->companyId($actor, 'finance.create')] + $data);
    }

    public function createInvoice(User $actor, array $data): Invoice
    {
        $companyId = $this->policy->companyId($actor, 'finance.create');
        $contact = FinanceContact::where('company_id', $companyId)->findOrFail($data['contact_id']);
        if ($contact->type !== 'both' && $contact->type !== ($data['type'] === 'payable' ? 'vendor' : 'customer')) {
            throw ValidationException::withMessages(['contact_id' => ['Contact type does not match invoice type.']]);
        }

        return DB::transaction(function () use ($companyId, $data) {
            $invoice = Invoice::create(['company_id' => $companyId] + collect($data)->except('lines')->all() + ['subtotal' => 0, 'discount_total' => 0, 'tax_total' => 0, 'total' => 0, 'paid_total' => 0, 'credited_total' => 0, 'status' => 'draft']);
            $subtotal = $discountTotal = $taxTotal = 0;
            foreach ($data['lines'] as $line) {
                $this->accounts->ensure($companyId, $line['account_id'], $data['type'] === 'receivable' ? 'revenue' : null, 'Invoice line account');
                $tax = $line['tax_rate_id'] ?? null ? TaxRate::where('company_id', $companyId)->findOrFail($line['tax_rate_id']) : null;
                $sub = round($line['quantity'] * $line['unit_price'], 4);
                $discountPercent = (float) ($line['discount_percent'] ?? 0);
                $discountAmount = round($sub * $discountPercent / 100, 4);
                $taxAmount = round(max(0, $sub - $discountAmount) * (float) ($tax?->rate ?? ($line['tax_percent'] ?? 0)) / 100, 4);
                $invoice->lines()->create(collect($line)->except('tax_percent')->all() + ['discount_percent' => $discountPercent, 'discount_amount' => $discountAmount, 'subtotal' => $sub, 'tax_amount' => $taxAmount, 'total' => $sub - $discountAmount + $taxAmount]);
                $subtotal += $sub;
                $discountTotal += $discountAmount;
                $taxTotal += $taxAmount;
            }
            $invoice->update(['subtotal' => $subtotal, 'discount_total' => $discountTotal, 'tax_total' => $taxTotal, 'total' => $subtotal - $discountTotal + $taxTotal]);

            return $invoice->load('contact', 'lines.account', 'lines.taxRate');
        });
    }

    public function post(User $actor, Invoice $invoice): Invoice
    {
        $this->policy->ensureOwned($actor, $invoice);
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft invoices can be posted.']]);
        }
        $control = $this->accounts->byCode($invoice->company_id, $invoice->type === 'receivable' ? '1100' : '2000', $invoice->type === 'receivable' ? 'asset' : 'liability');
        $tax = $invoice->tax_total > 0 ? $this->accounts->byCode($invoice->company_id, '2100', 'liability', 'Tax payable account') : null;
        $lines = [];
        if ($invoice->type === 'receivable') {
            $lines[] = ['account_id' => $control->id, 'debit' => $invoice->total, 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            foreach ($invoice->lines as $line) {
                $lines[] = ['account_id' => $line->account_id, 'debit' => 0, 'credit' => (float) $line->subtotal - (float) ($line->discount_amount ?? 0), 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            }
            if ($tax) {
                $lines[] = ['account_id' => $tax->id, 'debit' => 0, 'credit' => $invoice->tax_total, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            }
        } else {
            foreach ($invoice->lines as $line) {
                $lines[] = ['account_id' => $line->account_id, 'debit' => (float) $line->subtotal - (float) ($line->discount_amount ?? 0), 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            }
            if ($tax) {
                $lines[] = ['account_id' => $tax->id, 'debit' => $invoice->tax_total, 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            }
            $lines[] = ['account_id' => $control->id, 'debit' => 0, 'credit' => $invoice->total, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
        }

        return DB::transaction(function () use ($actor, $invoice, $lines) {
            $entry = $this->ledger->createForCompany($invoice->company_id, $actor->id, ['entry_date' => $invoice->invoice_date->toDateString(), 'description' => "Invoice {$invoice->number}", 'lines' => $lines], 'invoice', $invoice->id);
            $this->ledger->postSystem($entry, $actor->id);
            $invoice->update(['status' => 'posted', 'journal_entry_id' => $entry->id]);

            return $invoice->load('contact', 'lines', 'payments');
        });
    }

    public function pay(User $actor, array $data): Payment
    {
        $companyId = $this->policy->companyId($actor, 'finance.create');
        $invoice = Invoice::where('company_id', $companyId)->findOrFail($data['invoice_id']);
        $bank = $this->accounts->ensure($companyId, $data['account_id'], 'asset', 'Cash or bank account');
        $outstanding = (float) $invoice->total - (float) $invoice->paid_total - (float) ($invoice->credited_total ?? 0);
        if (! in_array($invoice->status, ['posted', 'partially_paid'], true) || (float) $data['amount'] > $outstanding) {
            throw ValidationException::withMessages(['amount' => ['Payment is invalid or exceeds the outstanding balance.']]);
        }
        $control = $this->accounts->byCode($companyId, $invoice->type === 'receivable' ? '1100' : '2000', $invoice->type === 'receivable' ? 'asset' : 'liability');
        $lines = $invoice->type === 'receivable'
            ? [['account_id' => $bank->id, 'debit' => $data['amount'], 'credit' => 0, 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1], ['account_id' => $control->id, 'debit' => 0, 'credit' => $data['amount'], 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1]]
            : [['account_id' => $control->id, 'debit' => $data['amount'], 'credit' => 0, 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1], ['account_id' => $bank->id, 'debit' => 0, 'credit' => $data['amount'], 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1]];

        return DB::transaction(function () use ($actor, $invoice, $data, $lines) {
            $payment = Payment::create(['company_id' => $invoice->company_id] + $data);
            $entry = $this->ledger->createForCompany($invoice->company_id, $actor->id, ['entry_date' => $data['payment_date'], 'description' => "Payment for {$invoice->number}", 'lines' => $lines], 'payment', $payment->id);
            $this->ledger->postSystem($entry, $actor->id);
            $payment->update(['journal_entry_id' => $entry->id]);
            $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount' => $data['amount']]);
            $paid = (float) $invoice->paid_total + (float) $data['amount'];
            $invoice->update(['paid_total' => $paid, 'status' => $paid >= ((float) $invoice->total - (float) ($invoice->credited_total ?? 0)) ? 'paid' : 'partially_paid']);

            return $payment->refresh()->load('allocations');
        });
    }

    public function credit(User $actor, Invoice $invoice, array $data): CreditNote
    {
        $this->policy->ensureOwned($actor, $invoice);
        if (! in_array($invoice->status, ['posted', 'partially_paid'], true)) {
            throw ValidationException::withMessages(['invoice_id' => ['Only posted or partially paid invoices can be credited.']]);
        }
        $amount = (float) $data['amount'];
        $outstanding = (float) $invoice->total - (float) $invoice->paid_total - (float) ($invoice->credited_total ?? 0);
        if ($amount <= 0 || $amount > $outstanding) {
            throw ValidationException::withMessages(['amount' => ['Credit amount must be positive and cannot exceed the open invoice balance.']]);
        }

        $control = $this->accounts->byCode($invoice->company_id, $invoice->type === 'receivable' ? '1100' : '2000', $invoice->type === 'receivable' ? 'asset' : 'liability');
        $lineAccount = $invoice->lines()->firstOrFail()->account;
        $lines = $invoice->type === 'receivable'
            ? [
                ['account_id' => $lineAccount->id, 'debit' => $amount, 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate],
                ['account_id' => $control->id, 'debit' => 0, 'credit' => $amount, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate],
            ]
            : [
                ['account_id' => $control->id, 'debit' => $amount, 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate],
                ['account_id' => $lineAccount->id, 'debit' => 0, 'credit' => $amount, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate],
            ];

        return DB::transaction(function () use ($actor, $invoice, $data, $amount, $lines) {
            $credit = CreditNote::create([
                'company_id' => $invoice->company_id,
                'invoice_id' => $invoice->id,
                'number' => $data['number'] ?? 'CN-'.$invoice->number.'-'.now()->format('His'),
                'credit_date' => $data['credit_date'],
                'amount' => $amount,
                'reason' => $data['reason'] ?? null,
            ]);
            $entry = $this->ledger->createForCompany($invoice->company_id, $actor->id, ['entry_date' => $data['credit_date'], 'description' => "Credit note {$credit->number}", 'lines' => $lines], 'credit_note', $credit->id);
            $this->ledger->postSystem($entry, $actor->id);
            $credit->update(['journal_entry_id' => $entry->id]);
            $credited = (float) ($invoice->credited_total ?? 0) + $amount;
            $invoice->update(['credited_total' => $credited, 'status' => ((float) $invoice->paid_total + $credited) >= (float) $invoice->total ? 'paid' : $invoice->status]);

            return $credit->refresh();
        });
    }

    public function schedule(User $actor, array $data): PaymentSchedule
    {
        $companyId = $this->policy->companyId($actor, 'finance.create');
        $invoice = Invoice::where('company_id', $companyId)->where('type', 'payable')->findOrFail($data['invoice_id']);
        $outstanding = (float) $invoice->total - (float) $invoice->paid_total;
        if ((float) $data['amount'] > $outstanding || $invoice->status === 'paid') {
            throw ValidationException::withMessages(['amount' => ['Scheduled payment exceeds the payable outstanding balance.']]);
        }

        return PaymentSchedule::create(['company_id' => $companyId] + $data);
    }

}
