<?php

namespace App\Modules\Finance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinanceRequest extends FormRequest
{
    public function rules(): array
    {
        return match ($this->route()?->getName()) {
            'finance.accounts.store', 'finance.accounts.update' => [
                'code' => ['required', 'string', 'max:30'], 'name' => ['required', 'string', 'max:255'],
                'type' => ['required', Rule::in(['asset', 'liability', 'equity', 'revenue', 'expense'])],
                'subtype' => ['nullable', 'string', 'max:100'], 'parent_id' => ['nullable', 'integer', 'exists:accounts,id'],
                'currency' => ['nullable', 'string', 'size:3'], 'is_active' => ['sometimes', 'boolean'], 'allow_manual_entries' => ['sometimes', 'boolean'],
            ],
            'finance.periods.store' => ['name' => ['required', 'string', 'max:100'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on']],
            'finance.journals.store' => [
                'entry_date' => ['required', 'date'], 'description' => ['required', 'string', 'max:500'],
                'lines' => ['required', 'array', 'min:2'], 'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
                'lines.*.description' => ['nullable', 'string', 'max:255'], 'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
                'lines.*.credit' => ['nullable', 'numeric', 'min:0'], 'lines.*.currency' => ['nullable', 'string', 'size:3'],
                'lines.*.exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            ],
            'finance.contacts.store' => ['type' => ['required', Rule::in(['vendor', 'customer', 'both'])], 'name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string'], 'tax_number' => ['nullable', 'string'], 'currency' => ['nullable', 'string', 'size:3'], 'address' => ['nullable', 'array']],
            'finance.taxes.store' => ['name' => ['required', 'string'], 'region' => ['nullable', 'string'], 'type' => ['required', Rule::in(['VAT', 'GST', 'sales_tax', 'withholding', 'other'])], 'rate' => ['required', 'numeric', 'min:0', 'max:100'], 'is_active' => ['sometimes', 'boolean']],
            'finance.invoices.store' => [
                'type' => ['required', Rule::in(['payable', 'receivable'])], 'contact_id' => ['required', 'integer', 'exists:finance_contacts,id'],
                'number' => ['required', 'string'], 'invoice_date' => ['required', 'date'], 'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
                'currency' => ['required', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string'],
                'lines' => ['required', 'array', 'min:1'], 'lines.*.account_id' => ['required', 'integer', 'exists:accounts,id'],
                'lines.*.tax_rate_id' => ['nullable', 'integer', 'exists:tax_rates,id'], 'lines.*.description' => ['required', 'string'],
                'lines.*.quantity' => ['required', 'numeric', 'gt:0'], 'lines.*.unit_price' => ['required', 'numeric', 'min:0'],
            ],
            'finance.payments.store' => ['invoice_id' => ['required', 'integer', 'exists:invoices,id'], 'account_id' => ['required', 'integer', 'exists:accounts,id'], 'payment_date' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'currency' => ['required', 'string', 'size:3'], 'exchange_rate' => ['nullable', 'numeric', 'gt:0'], 'reference' => ['nullable', 'string']],
            'finance.payment-schedules.store' => ['invoice_id' => ['required', 'integer', 'exists:invoices,id'], 'scheduled_for' => ['required', 'date'], 'amount' => ['required', 'numeric', 'gt:0'], 'notes' => ['nullable', 'string']],
            'finance.bank-accounts.store' => ['account_id' => ['required', 'integer', 'exists:accounts,id'], 'name' => ['required', 'string'], 'bank_name' => ['required', 'string'], 'account_number' => ['nullable', 'string'], 'currency' => ['required', 'string', 'size:3'], 'opening_balance' => ['nullable', 'numeric']],
            'finance.bank-transactions.store' => ['bank_account_id' => ['required', 'integer', 'exists:bank_accounts,id'], 'transaction_date' => ['required', 'date'], 'description' => ['required', 'string'], 'reference' => ['nullable', 'string'], 'amount' => ['required', 'numeric', 'not_in:0']],
            'finance.bank-transactions.reconcile' => ['journal_entry_id' => ['required', 'integer', 'exists:journal_entries,id']],
            'finance.budgets.store' => ['name' => ['required', 'string'], 'starts_on' => ['required', 'date'], 'ends_on' => ['required', 'date', 'after_or_equal:starts_on'], 'status' => ['sometimes', Rule::in(['draft', 'approved', 'closed'])], 'lines' => ['required', 'array', 'min:1'], 'lines.*.account_id' => ['required', 'integer', 'distinct', 'exists:accounts,id'], 'lines.*.amount' => ['required', 'numeric']],
            'finance.exchange-rates.store' => ['base_currency' => ['required', 'string', 'size:3'], 'quote_currency' => ['required', 'string', 'size:3', 'different:base_currency'], 'rate' => ['required', 'numeric', 'gt:0'], 'rate_date' => ['required', 'date'], 'source' => ['nullable', 'string']],
            default => [],
        };
    }
}
