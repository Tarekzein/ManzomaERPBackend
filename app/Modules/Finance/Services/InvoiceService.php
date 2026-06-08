<?php

namespace App\Modules\Finance\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
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
    public function __construct(private readonly FinancePolicy $policy, private readonly LedgerService $ledger) {}

    public function contacts(User $actor)
    {
        return FinanceContact::where('company_id', $this->policy->companyId($actor))->latest()->get();
    }

    public function invoices(User $actor, ?string $type = null)
    {
        return Invoice::with('contact', 'lines.account', 'lines.taxRate', 'payments')->where('company_id', $this->policy->companyId($actor))->when($type, fn ($q) => $q->where('type', $type))->latest()->get();
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
            $invoice = Invoice::create(['company_id' => $companyId] + collect($data)->except('lines')->all() + ['subtotal' => 0, 'tax_total' => 0, 'total' => 0, 'paid_total' => 0, 'status' => 'draft']);
            $subtotal = $taxTotal = 0;
            foreach ($data['lines'] as $line) {
                Account::where('company_id', $companyId)->findOrFail($line['account_id']);
                $tax = $line['tax_rate_id'] ?? null ? TaxRate::where('company_id', $companyId)->findOrFail($line['tax_rate_id']) : null;
                $sub = round($line['quantity'] * $line['unit_price'], 4);
                $taxAmount = round($sub * (float) ($tax?->rate ?? 0) / 100, 4);
                $invoice->lines()->create($line + ['subtotal' => $sub, 'tax_amount' => $taxAmount, 'total' => $sub + $taxAmount]);
                $subtotal += $sub;
                $taxTotal += $taxAmount;
            }
            $invoice->update(['subtotal' => $subtotal, 'tax_total' => $taxTotal, 'total' => $subtotal + $taxTotal]);

            return $invoice->load('contact', 'lines.account', 'lines.taxRate');
        });
    }

    public function post(User $actor, Invoice $invoice): Invoice
    {
        $this->policy->ensureOwned($actor, $invoice);
        if ($invoice->status !== 'draft') {
            throw ValidationException::withMessages(['status' => ['Only draft invoices can be posted.']]);
        }
        $control = $this->control($invoice->company_id, $invoice->type === 'receivable' ? '1100' : '2000');
        $tax = $invoice->tax_total > 0 ? $this->control($invoice->company_id, '2100') : null;
        $lines = [];
        if ($invoice->type === 'receivable') {
            $lines[] = ['account_id' => $control->id, 'debit' => $invoice->total, 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            foreach ($invoice->lines as $line) {
                $lines[] = ['account_id' => $line->account_id, 'debit' => 0, 'credit' => $line->subtotal, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            }
            if ($tax) {
                $lines[] = ['account_id' => $tax->id, 'debit' => 0, 'credit' => $invoice->tax_total, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
            }
        } else {
            foreach ($invoice->lines as $line) {
                $lines[] = ['account_id' => $line->account_id, 'debit' => $line->subtotal, 'credit' => 0, 'currency' => $invoice->currency, 'exchange_rate' => $invoice->exchange_rate];
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
        $bank = Account::where('company_id', $companyId)->findOrFail($data['account_id']);
        if ($invoice->status === 'draft' || $invoice->status === 'paid' || (float) $data['amount'] > (float) $invoice->total - (float) $invoice->paid_total) {
            throw ValidationException::withMessages(['amount' => ['Payment is invalid or exceeds the outstanding balance.']]);
        }
        $control = $this->control($companyId, $invoice->type === 'receivable' ? '1100' : '2000');
        $lines = $invoice->type === 'receivable'
            ? [['account_id' => $bank->id, 'debit' => $data['amount'], 'credit' => 0, 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1], ['account_id' => $control->id, 'debit' => 0, 'credit' => $data['amount'], 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1]]
            : [['account_id' => $control->id, 'debit' => $data['amount'], 'credit' => 0, 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1], ['account_id' => $bank->id, 'debit' => 0, 'credit' => $data['amount'], 'currency' => $data['currency'], 'exchange_rate' => $data['exchange_rate'] ?? 1]];

        return DB::transaction(function () use ($actor, $invoice, $data, $lines) {
            $payment = Payment::create(['company_id' => $invoice->company_id] + $data);
            $entry = $this->ledger->createForCompany($invoice->company_id, $actor->id, ['entry_date' => $data['payment_date'], 'description' => "Payment for {$invoice->number}", 'lines' => $lines], 'payment', $payment->id);
            $this->ledger->postSystem($entry, $actor->id);
            $payment->update(['journal_entry_id' => $entry->id]);
            $paid = (float) $invoice->paid_total + (float) $data['amount'];
            $invoice->update(['paid_total' => $paid, 'status' => $paid >= (float) $invoice->total ? 'paid' : 'partially_paid']);

            return $payment->refresh();
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

    private function control(int $companyId, string $code): Account
    {
        return Account::where('company_id', $companyId)->where('code', $code)->firstOrFail();
    }
}
