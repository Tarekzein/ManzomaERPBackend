<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinancialPeriod;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FinanceModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_company_admin_can_manage_chart_and_post_balanced_journal(): void
    {
        $admin = $this->companyAdmin();
        $cash = Account::where('company_id', $admin->company_id)->where('code', '1000')->firstOrFail();
        $equity = Account::where('company_id', $admin->company_id)->where('code', '3000')->firstOrFail();

        $journal = $this->postJson('/api/finance/journals', [
            'entry_date' => now()->toDateString(),
            'description' => 'Opening capital',
            'lines' => [
                ['account_id' => $cash->id, 'debit' => 1000, 'credit' => 0, 'currency' => 'EGP'],
                ['account_id' => $equity->id, 'debit' => 0, 'credit' => 1000, 'currency' => 'EGP'],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');

        $this->postJson("/api/finance/journals/{$journal['id']}/post")
            ->assertOk()->assertJsonPath('data.status', 'posted');

        $this->getJson('/api/finance/reports/trial-balance')
            ->assertOk()->assertJsonFragment(['code' => '1000', 'balance' => 1000]);
    }

    public function test_unbalanced_journal_and_locked_period_posting_are_rejected(): void
    {
        $admin = $this->companyAdmin();
        $cash = Account::where('company_id', $admin->company_id)->where('code', '1000')->firstOrFail();
        $equity = Account::where('company_id', $admin->company_id)->where('code', '3000')->firstOrFail();

        $this->postJson('/api/finance/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Unbalanced',
            'lines' => [['account_id' => $cash->id, 'debit' => 100], ['account_id' => $equity->id, 'credit' => 90]],
        ])->assertUnprocessable();

        $period = FinancialPeriod::where('company_id', $admin->company_id)->firstOrFail();
        $this->postJson("/api/finance/periods/{$period->id}/lock")->assertOk();

        $this->postJson('/api/finance/journals', [
            'entry_date' => now()->toDateString(), 'description' => 'Locked',
            'lines' => [['account_id' => $cash->id, 'debit' => 100], ['account_id' => $equity->id, 'credit' => 100]],
        ])->assertUnprocessable();
    }

    public function test_receivable_invoice_and_receipt_post_to_ledger(): void
    {
        $admin = $this->companyAdmin();
        $revenue = Account::where('company_id', $admin->company_id)->where('code', '4000')->firstOrFail();
        $cash = Account::where('company_id', $admin->company_id)->where('code', '1000')->firstOrFail();

        $contact = $this->postJson('/api/finance/contacts', ['type' => 'customer', 'name' => 'Customer One'])
            ->assertCreated()->json('data');
        $invoice = $this->postJson('/api/finance/invoices', [
            'type' => 'receivable', 'contact_id' => $contact['id'], 'number' => 'INV-001',
            'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'currency' => 'EGP', 'lines' => [['account_id' => $revenue->id, 'description' => 'Services', 'quantity' => 1, 'unit_price' => 500]],
        ])->assertCreated()->assertJsonPath('data.total', 500)->json('data');

        $this->postJson("/api/finance/invoices/{$invoice['id']}/post")->assertOk()->assertJsonPath('data.status', 'posted');
        $this->postJson('/api/finance/payments', [
            'invoice_id' => $invoice['id'], 'account_id' => $cash->id, 'payment_date' => now()->toDateString(),
            'amount' => 500, 'currency' => 'EGP', 'reference' => 'RECEIPT-001',
        ])->assertCreated();
        $this->assertDatabaseHas('invoices', ['id' => $invoice['id'], 'status' => 'paid']);
        $this->assertDatabaseCount('journal_entries', 2);
    }

    public function test_budget_variance_tax_and_exchange_rates_are_available(): void
    {
        $admin = $this->companyAdmin();
        $expense = Account::where('company_id', $admin->company_id)->where('code', '6000')->firstOrFail();

        $this->postJson('/api/finance/taxes', ['name' => 'VAT 14%', 'type' => 'VAT', 'region' => 'EG', 'rate' => 14, 'is_active' => true])->assertCreated();
        $this->postJson('/api/finance/exchange-rates', ['base_currency' => 'EGP', 'quote_currency' => 'USD', 'rate' => 0.02, 'rate_date' => now()->toDateString()])->assertCreated();
        $budget = $this->postJson('/api/finance/budgets', [
            'name' => 'Annual Budget', 'starts_on' => now()->startOfYear()->toDateString(), 'ends_on' => now()->endOfYear()->toDateString(),
            'status' => 'approved', 'lines' => [['account_id' => $expense->id, 'amount' => 10000]],
        ])->assertCreated()->json('data');
        $this->getJson("/api/finance/budgets/{$budget['id']}/variance")->assertOk()->assertJsonPath('data.0.budget', 10000);
    }

    public function test_payable_aging_payment_scheduling_and_statement_export_work(): void
    {
        $admin = $this->companyAdmin();
        $expense = Account::where('company_id', $admin->company_id)->where('code', '6000')->firstOrFail();
        $vendor = $this->postJson('/api/finance/contacts', ['type' => 'vendor', 'name' => 'Vendor One'])->assertCreated()->json('data');
        $invoice = $this->postJson('/api/finance/invoices', [
            'type' => 'payable', 'contact_id' => $vendor['id'], 'number' => 'BILL-001',
            'invoice_date' => now()->subDays(45)->toDateString(), 'due_date' => now()->subDays(15)->toDateString(),
            'currency' => 'EGP', 'lines' => [['account_id' => $expense->id, 'description' => 'Rent', 'quantity' => 1, 'unit_price' => 1000]],
        ])->assertCreated()->json('data');
        $this->postJson("/api/finance/invoices/{$invoice['id']}/post")->assertOk();

        $this->postJson('/api/finance/payment-schedules', [
            'invoice_id' => $invoice['id'], 'scheduled_for' => now()->addDays(3)->toDateString(), 'amount' => 1000,
        ])->assertCreated();
        $this->getJson('/api/finance/reports/aging/payable')->assertOk()
            ->assertJsonPath('data.0.invoice.number', 'BILL-001')
            ->assertJsonPath('data.0.bucket', '1-30');
        $this->get('/api/finance/reports/profit-loss?format=pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    private function companyAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
