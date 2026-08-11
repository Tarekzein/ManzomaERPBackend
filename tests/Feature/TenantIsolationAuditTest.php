<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMTask;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\Budget;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\FinancialPeriod;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\HR\Models\Applicant;
use App\Modules\HR\Models\Department;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\JobPosting;
use App\Modules\HR\Models\PayrollItem;
use App\Modules\HR\Models\PayrollRun;
use App\Modules\HR\Models\Position;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Reporting\Models\ReportAlert;
use App\Modules\Reporting\Models\ReportDefinition;
use App\Modules\Reporting\Models\ReportSchedule;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The module-by-module isolation audit: for every model-bound endpoint in the
 * business modules, a company admin must not be able to read, change or delete
 * another company's record by guessing its id.
 *
 * Ownership is enforced in the service layer here, not by a global scope, so
 * a missed `ensureOwned()` in one method is invisible to code review — these
 * tests are the check that catches it.
 */
class TenantIsolationAuditTest extends TestCase
{
    use RefreshDatabase;

    private Company $other;

    public function test_finance_records_of_another_company_are_unreachable(): void
    {
        $this->admin();
        $companyId = $this->other->id;

        $account = Account::create(['company_id' => $companyId, 'code' => '1000', 'name' => 'Cash', 'type' => 'asset']);
        $period = FinancialPeriod::create([
            'company_id' => $companyId, 'name' => 'FY26',
            'starts_on' => now()->startOfYear(), 'ends_on' => now()->endOfYear(),
        ]);
        $journal = JournalEntry::create([
            'company_id' => $companyId, 'financial_period_id' => $period->id, 'number' => 'JV-1',
            'entry_date' => now(), 'description' => 'Theirs',
        ]);
        $budget = Budget::create([
            'company_id' => $companyId, 'name' => 'Their budget',
            'starts_on' => now()->startOfYear(), 'ends_on' => now()->endOfYear(),
        ]);
        $contact = FinanceContact::create(['company_id' => $companyId, 'type' => 'customer', 'name' => 'Their customer']);
        $invoice = Invoice::create([
            'company_id' => $companyId, 'contact_id' => $contact->id, 'type' => 'sales', 'number' => 'INV-1',
            'invoice_date' => now(), 'due_date' => now()->addDays(30), 'currency' => 'EGP',
        ]);

        $this->assertDenied([
            ['put', "/api/finance/accounts/{$account->id}", ['code' => '1000', 'name' => 'Hijacked', 'type' => 'asset']],
            ['post', "/api/finance/periods/{$period->id}/lock"],
            ['post', "/api/finance/journals/{$journal->id}/post"],
            ['get', "/api/finance/budgets/{$budget->id}/variance"],
            ['get', "/api/finance/reports/contacts/{$contact->id}/statement"],
            ['post', "/api/finance/invoices/{$invoice->id}/post"],
            ['post', "/api/finance/invoices/{$invoice->id}/credit", ['reason' => 'test']],
        ]);
    }

    public function test_inventory_records_of_another_company_are_unreachable(): void
    {
        $this->admin();
        $companyId = $this->other->id;

        $unit = Unit::create(['company_id' => $companyId, 'name' => 'Piece', 'symbol' => 'pc']);
        $category = ProductCategory::create(['company_id' => $companyId, 'name' => 'Theirs', 'code' => 'THR']);
        $product = Product::create(['company_id' => $companyId, 'unit_id' => $unit->id, 'sku' => 'THEIR-1', 'name' => 'Their product']);
        $warehouse = Warehouse::create(['company_id' => $companyId, 'code' => 'THR', 'name' => 'Their warehouse']);
        $balance = StockBalance::create(['company_id' => $companyId, 'product_id' => $product->id, 'warehouse_id' => $warehouse->id]);

        $this->assertDenied([
            ['put', "/api/inventory/units/{$unit->id}", ['name' => 'Hijacked', 'symbol' => 'hj']],
            ['put', "/api/inventory/categories/{$category->id}", ['name' => 'Hijacked', 'code' => 'HJK']],
            ['put', "/api/inventory/products/{$product->id}", ['sku' => 'HIJACK', 'name' => 'Hijacked', 'unit_id' => $unit->id]],
            ['put', "/api/inventory/warehouses/{$warehouse->id}", ['code' => 'HJK', 'name' => 'Hijacked']],
            ['put', "/api/inventory/balances/{$balance->id}/reorder", ['reorder_point' => 5]],
        ]);
    }

    public function test_sales_records_of_another_company_are_unreachable(): void
    {
        $this->admin();
        $companyId = $this->other->id;

        $contact = SalesContact::create(['company_id' => $companyId, 'type' => 'customer', 'name' => 'Their customer']);
        $priceList = PriceList::create(['company_id' => $companyId, 'name' => 'Their prices']);
        $quotation = SalesQuotation::create([
            'company_id' => $companyId, 'customer_id' => $contact->id, 'number' => 'QT-1', 'quote_date' => now(),
        ]);
        $order = SalesOrder::create([
            'company_id' => $companyId, 'customer_id' => $contact->id, 'number' => 'SO-1', 'order_date' => now(),
        ]);
        $purchase = PurchaseOrder::create([
            'company_id' => $companyId, 'vendor_id' => $contact->id, 'number' => 'PO-1', 'order_date' => now(),
        ]);

        $this->assertDenied([
            ['put', "/api/sales/contacts/{$contact->id}", ['type' => 'customer', 'name' => 'Hijacked']],
            ['put', "/api/sales/price-lists/{$priceList->id}", ['name' => 'Hijacked', 'items' => []]],
            ['post', "/api/sales/quotations/{$quotation->id}/convert"],
            ['get', "/api/sales/quotations/{$quotation->id}/pdf"],
            ['post', "/api/sales/orders/{$order->id}/confirm"],
            ['post', "/api/sales/orders/{$order->id}/close"],
            ['get', "/api/sales/orders/{$order->id}/invoice-pdf"],
            ['post', "/api/sales/purchase-orders/{$purchase->id}/confirm"],
            ['get', "/api/sales/purchase-orders/{$purchase->id}/pdf"],
        ]);
    }

    public function test_hr_records_of_another_company_are_unreachable(): void
    {
        $this->admin();
        $companyId = $this->other->id;

        $department = Department::create(['company_id' => $companyId, 'code' => 'THR', 'name' => 'Their dept']);
        $position = Position::create(['company_id' => $companyId, 'code' => 'THR-1', 'title' => 'Their role']);
        $employee = Employee::create([
            'company_id' => $companyId, 'employee_number' => 'THR-001', 'name' => 'Their employee', 'hire_date' => now(),
        ]);
        $job = JobPosting::create(['company_id' => $companyId, 'title' => 'Their opening']);
        $applicant = Applicant::create([
            'company_id' => $companyId, 'job_posting_id' => $job->id, 'name' => 'Their applicant', 'email' => 'them@example.com',
        ]);
        $run = PayrollRun::create([
            'company_id' => $companyId, 'name' => 'Their payroll',
            'period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'pay_date' => now(),
        ]);
        $item = PayrollItem::create([
            'payroll_run_id' => $run->id, 'employee_id' => $employee->id,
            'base_salary' => 1000, 'gross_salary' => 1000, 'net_salary' => 900, 'currency' => 'EGP',
        ]);

        $this->assertDenied([
            ['put', "/api/hr/departments/{$department->id}", ['code' => 'HJK', 'name' => 'Hijacked']],
            ['put', "/api/hr/positions/{$position->id}", ['code' => 'HJK', 'title' => 'Hijacked']],
            ['get', "/api/hr/employees/{$employee->id}"],
            ['put', "/api/hr/employees/{$employee->id}", ['employee_number' => 'HJK', 'name' => 'Hijacked', 'hire_date' => now()->toDateString()]],
            ['put', "/api/hr/jobs/{$job->id}", ['title' => 'Hijacked']],
            ['put', "/api/hr/applicants/{$applicant->id}", ['name' => 'Hijacked', 'email' => 'hijack@example.com', 'job_posting_id' => $job->id]],
            ['post', "/api/hr/payroll-runs/{$run->id}/process"],
            ['get', "/api/hr/payslips/{$item->id}/pdf"],
            ['post', "/api/hr/payslips/{$item->id}/email"],
        ]);
    }

    public function test_projects_of_another_company_are_unreachable(): void
    {
        $this->admin();

        $theirAdmin = $this->userFor($this->other);
        $project = Project::create([
            'company_id' => $this->other->id, 'owner_id' => $theirAdmin->id, 'name' => 'Their project',
        ]);
        $task = ProjectTask::create(['project_id' => $project->id, 'title' => 'Their task']);

        $this->assertDenied([
            ['get', "/api/projects/{$project->id}"],
            ['put', "/api/projects/{$project->id}", ['name' => 'Hijacked']],
            ['delete', "/api/projects/{$project->id}"],
            ['get', "/api/projects/{$project->id}/report"],
            ['get', "/api/projects/{$project->id}/tasks"],
            ['post', "/api/projects/{$project->id}/comments", ['body' => 'Hijack']],
            ['get', "/api/project-tasks/{$task->id}"],
            ['patch', "/api/project-tasks/{$task->id}", ['title' => 'Hijacked']],
            ['delete', "/api/project-tasks/{$task->id}"],
        ]);
    }

    public function test_reporting_records_of_another_company_are_unreachable(): void
    {
        $this->admin();
        $companyId = $this->other->id;

        $report = ReportDefinition::create([
            'company_id' => $companyId, 'name' => 'Their report', 'source' => 'crm_contacts', 'fields' => ['id'],
        ]);
        $schedule = ReportSchedule::create([
            'company_id' => $companyId, 'report_definition_id' => $report->id,
            'name' => 'Their schedule', 'recipients' => ['them@example.com'],
        ]);
        $alert = ReportAlert::create([
            'company_id' => $companyId, 'report_definition_id' => $report->id, 'name' => 'Their alert',
            'metric_field' => 'id', 'operator' => '>', 'threshold_value' => 1, 'recipients' => ['them@example.com'],
        ]);

        $this->assertDenied([
            ['put', "/api/reporting/reports/{$report->id}", ['name' => 'Hijacked', 'source' => 'crm_contacts', 'fields' => ['id']]],
            ['delete', "/api/reporting/reports/{$report->id}"],
            ['post', "/api/reporting/reports/{$report->id}/run"],
            ['get', "/api/reporting/reports/{$report->id}/export/csv"],
            ['put', "/api/reporting/schedules/{$schedule->id}", ['name' => 'Hijacked', 'recipients' => ['me@example.com']]],
            ['delete', "/api/reporting/schedules/{$schedule->id}"],
            ['put', "/api/reporting/alerts/{$alert->id}", ['name' => 'Hijacked', 'metric_field' => 'id', 'operator' => '>', 'threshold_value' => 2, 'recipients' => ['me@example.com']]],
            ['delete', "/api/reporting/alerts/{$alert->id}"],
        ]);
    }

    public function test_crm_records_of_another_company_are_unreachable(): void
    {
        $this->admin();
        $companyId = $this->other->id;

        $contact = CRMContact::create([
            'company_id' => $companyId, 'name' => 'Their lead', 'type' => 'lead', 'status' => 'new', 'currency' => 'EGP',
        ]);
        $task = CRMTask::create([
            'company_id' => $companyId, 'contact_id' => $contact->id, 'title' => 'Their task', 'status' => 'open',
        ]);

        $this->assertDenied([
            ['put', "/api/crm/contacts/{$contact->id}", ['name' => 'Hijacked', 'type' => 'lead', 'status' => 'new']],
            ['delete', "/api/crm/contacts/{$contact->id}"],
            ['post', "/api/crm/contacts/{$contact->id}/convert"],
            ['post', "/api/crm/contacts/{$contact->id}/refresh-score"],
            ['put', "/api/crm/tasks/{$task->id}", ['title' => 'Hijacked', 'status' => 'open']],
            ['delete', "/api/crm/tasks/{$task->id}"],
            ['post', "/api/crm/tasks/{$task->id}/complete"],
        ]);
    }

    /**
     * A refused request is 403 (ownership), 404 (scoped lookup) or 422
     * (validated as belonging elsewhere). Anything in the 2xx range means the
     * caller reached another company's data.
     *
     * @param  array<int, array{0: string, 1: string, 2?: array<string, mixed>}>  $calls
     */
    private function assertDenied(array $calls): void
    {
        foreach ($calls as $call) {
            [$method, $uri] = $call;
            $response = $this->json(strtoupper($method), $uri, $call[2] ?? []);

            $this->assertContains(
                $response->status(),
                [403, 404, 422],
                strtoupper($method)." {$uri} returned {$response->status()} — another company's record was reachable."
            );
        }
    }

    private function userFor(Company $company): User
    {
        return User::create([
            'name' => 'Their admin',
            'email' => 'their.admin'.$company->id.'@example.com',
            'password' => bcrypt('password'),
            'company_id' => $company->id,
        ]);
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $this->other = Company::create([
            'name' => 'Other Tenant',
            'email' => 'other-tenant@example.com',
            'status' => 'active',
        ]);

        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }
}
