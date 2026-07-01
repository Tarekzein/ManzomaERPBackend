<?php

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentSchedule;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Finance\Services\InvoiceService;
use Illuminate\Database\Seeder;

class FinanceSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->where('is_active', true)->each(function (Company $company): void {
            app(FinanceSetupService::class)->provision($company);
            $this->seedCompanyDocuments($company);
        });
    }

    private function seedCompanyDocuments(Company $company): void
    {
        $admin = User::where('company_id', $company->id)->whereHas('roles', fn ($query) => $query->where('name', 'Company Admin'))->first()
            ?? User::where('company_id', $company->id)->first();

        if (! $admin) {
            return;
        }

        $service = app(InvoiceService::class);
        $cash = Account::where('company_id', $company->id)->where('code', '1000')->firstOrFail();
        $revenue = Account::where('company_id', $company->id)->where('code', '4000')->firstOrFail();
        $expense = Account::where('company_id', $company->id)->where('code', '6000')->firstOrFail();

        $customer = FinanceContact::updateOrCreate(
            ['company_id' => $company->id, 'email' => 'finance.customer@seed.example'],
            ['type' => 'customer', 'name' => 'Seed Finance Customer', 'phone' => '+201000000303', 'currency' => $company->currency],
        );
        $vendor = FinanceContact::updateOrCreate(
            ['company_id' => $company->id, 'email' => 'finance.vendor@seed.example'],
            ['type' => 'vendor', 'name' => 'Seed Finance Vendor', 'phone' => '+201000000404', 'currency' => $company->currency],
        );

        $receivable = Invoice::where('company_id', $company->id)->where('number', 'AR-SEED-001')->first();
        if (! $receivable) {
            $receivable = $service->createInvoice($admin, [
                'type' => 'receivable',
                'contact_id' => $customer->id,
                'number' => 'AR-SEED-001',
                'invoice_date' => now()->subDays(12)->toDateString(),
                'due_date' => now()->addDays(18)->toDateString(),
                'currency' => $company->currency,
                'notes' => 'Seeded receivable with discount and tax.',
                'lines' => [
                    ['account_id' => $revenue->id, 'description' => 'ERP implementation services', 'quantity' => 1, 'unit_price' => 25000, 'discount_percent' => 5, 'tax_percent' => 14],
                    ['account_id' => $revenue->id, 'description' => 'Training package', 'quantity' => 2, 'unit_price' => 3500, 'discount_percent' => 0, 'tax_percent' => 14],
                ],
            ]);
        }
        if ($receivable->status === 'draft') {
            $receivable = $service->post($admin, $receivable);
        }

        $receivable = $receivable->fresh();
        if (! Payment::where('company_id', $company->id)->where('reference', 'PAY-SEED-AR-001')->exists() && (float) $receivable->balance > 0) {
            $service->pay($admin, [
                'invoice_id' => $receivable->id,
                'account_id' => $cash->id,
                'payment_date' => now()->subDays(5)->toDateString(),
                'amount' => min(10000, (float) $receivable->balance),
                'currency' => $company->currency,
                'reference' => 'PAY-SEED-AR-001',
            ]);
        }

        $receivable = $receivable->fresh();
        if (! CreditNote::where('company_id', $company->id)->where('number', 'CN-SEED-AR-001')->exists() && (float) $receivable->balance > 500) {
            $service->credit($admin, $receivable, [
                'number' => 'CN-SEED-AR-001',
                'credit_date' => now()->toDateString(),
                'amount' => 500,
                'reason' => 'Seeded commercial adjustment.',
            ]);
        }

        $payable = Invoice::where('company_id', $company->id)->where('number', 'AP-SEED-001')->first();
        if (! $payable) {
            $payable = $service->createInvoice($admin, [
                'type' => 'payable',
                'contact_id' => $vendor->id,
                'number' => 'AP-SEED-001',
                'invoice_date' => now()->subDays(20)->toDateString(),
                'due_date' => now()->addDays(10)->toDateString(),
                'currency' => $company->currency,
                'notes' => 'Seeded vendor bill with tax.',
                'lines' => [
                    ['account_id' => $expense->id, 'description' => 'Cloud infrastructure services', 'quantity' => 1, 'unit_price' => 8000, 'discount_percent' => 0, 'tax_percent' => 14],
                ],
            ]);
        }
        if ($payable->status === 'draft') {
            $payable = $service->post($admin, $payable);
        }

        PaymentSchedule::updateOrCreate(
            ['company_id' => $company->id, 'invoice_id' => $payable->id, 'scheduled_for' => now()->addDays(7)->toDateString()],
            ['amount' => $payable->balance, 'status' => 'scheduled', 'notes' => 'Seeded payable schedule.'],
        );
    }
}
