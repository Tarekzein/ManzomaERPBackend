<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Models\Account;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\JournalEntry;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesOrder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SalesModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_sales_quotation_order_inventory_invoice_pdf_and_reports_work(): void
    {
        $admin = $this->admin();
        $baselineOrders = SalesOrder::where('company_id', $admin->company_id)->count();
        $baselineOpenOrders = SalesOrder::where('company_id', $admin->company_id)->whereNotIn('status', ['closed'])->count();
        [$product, $warehouse] = $this->productAndWarehouse($admin->company_id);
        $financeCustomer = FinanceContact::create(['company_id' => $admin->company_id, 'type' => 'customer', 'name' => 'Finance Customer', 'currency' => 'EGP']);
        $customer = $this->postJson('/api/sales/contacts', ['finance_contact_id' => $financeCustomer->id, 'type' => 'customer', 'name' => 'Customer One', 'currency' => 'EGP'])
            ->assertCreated()->json('data');

        $priceList = $this->postJson('/api/sales/price-lists', [
            'contact_id' => $customer['id'], 'name' => 'Customer pricing', 'is_active' => true,
            'items' => [['product_id' => $product->id, 'unit_price' => 150, 'discount_percent' => 10]],
        ])->assertCreated()->json('data');
        $this->assertSame(150.0, (float) $priceList['items'][0]['unit_price']);

        $quote = $this->postJson('/api/sales/quotations', [
            'customer_id' => $customer['id'], 'quote_date' => '2026-06-14', 'valid_until' => '2026-07-14', 'currency' => 'EGP',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'tax_percent' => 14]],
        ])->assertCreated()->json('data');
        $this->assertSame(307.8, (float) $quote['total']);
        $this->get("/api/sales/quotations/{$quote['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $order = $this->postJson("/api/sales/quotations/{$quote['id']}/convert")->assertCreated()->json('data');
        $this->putJson("/api/sales/orders/{$order['id']}", [
            'customer_id' => $customer['id'], 'warehouse_id' => $warehouse->id, 'order_date' => '2026-06-14', 'expected_ship_date' => '2026-06-15', 'currency' => 'EGP',
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'tax_percent' => 14]],
        ])->assertOk();

        $this->postJson("/api/sales/orders/{$order['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $this->postJson("/api/sales/orders/{$order['id']}/ship")->assertOk()->assertJsonPath('data.status', 'shipped');
        $revenue = Account::where('company_id', $admin->company_id)->where('code', '4000')->firstOrFail();
        $this->postJson("/api/sales/orders/{$order['id']}/invoice", ['revenue_account_id' => $revenue->id])
            ->assertOk()->assertJsonPath('data.status', 'invoiced');
        $this->assertDatabaseHas('invoices', ['company_id' => $admin->company_id, 'type' => 'receivable', 'status' => 'posted']);
        $financeInvoice = Invoice::where('company_id', $admin->company_id)->where('type', 'receivable')->latest('id')->firstOrFail();
        $salesOrder = SalesOrder::findOrFail($order['id'])->fresh();
        $this->assertEquals((float) $salesOrder->total, (float) $financeInvoice->total);
        $this->assertEquals((float) $salesOrder->discount_total, (float) $financeInvoice->discount_total);
        $this->assertDatabaseHas('journal_entries', ['source_type' => 'sales_order_cogs', 'source_id' => $order['id'], 'status' => 'posted']);
        $this->get("/api/sales/orders/{$order['id']}/invoice-pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get("/api/sales/orders/{$order['id']}/delivery-note")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->getJson('/api/sales/reports/order-volume')->assertOk()->assertJsonPath('data.sales_orders', $baselineOrders + 1);
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('data.metrics.open_sales_orders', $baselineOpenOrders + 1);
    }

    public function test_purchase_order_receipt_three_way_match_and_pdf_work(): void
    {
        $admin = $this->admin();
        $baselinePurchaseOrders = PurchaseOrder::where('company_id', $admin->company_id)->count();
        [$product, $warehouse] = $this->productAndWarehouse($admin->company_id);
        $financeVendor = FinanceContact::create(['company_id' => $admin->company_id, 'type' => 'vendor', 'name' => 'Finance Vendor', 'currency' => 'EGP']);
        $vendor = $this->postJson('/api/sales/contacts', ['finance_contact_id' => $financeVendor->id, 'type' => 'vendor', 'name' => 'Vendor One', 'currency' => 'EGP'])
            ->assertCreated()->json('data');

        $po = $this->postJson('/api/sales/purchase-orders', [
            'vendor_id' => $vendor['id'], 'warehouse_id' => $warehouse->id, 'order_date' => '2026-06-14', 'expected_receipt_date' => '2026-06-20', 'currency' => 'EGP',
            'lines' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 80]],
        ])->assertCreated()->json('data');
        $this->get("/api/sales/purchase-orders/{$po['id']}/pdf")->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->postJson("/api/sales/purchase-orders/{$po['id']}/confirm")->assertOk()->assertJsonPath('data.status', 'confirmed');
        $lineId = PurchaseOrder::with('lines')->findOrFail($po['id'])->lines->first()->id;
        $receipt = $this->postJson("/api/sales/purchase-orders/{$po['id']}/receive", ['received_on' => '2026-06-15', 'lines' => [['purchase_order_line_id' => $lineId, 'quantity_received' => 5]]])
            ->assertCreated()->json('data');
        $this->assertSame(5.0, (float) $receipt['lines'][0]['quantity_received']);
        $expense = Account::where('company_id', $admin->company_id)->where('code', '5000')->firstOrFail();
        $this->postJson("/api/sales/purchase-orders/{$po['id']}/match", ['expense_account_id' => $expense->id])
            ->assertOk()->assertJsonPath('data.status', 'matched');
        $this->assertDatabaseHas('invoices', ['company_id' => $admin->company_id, 'type' => 'payable', 'status' => 'posted']);
        $this->getJson('/api/sales/reports/order-volume')->assertOk()->assertJsonPath('data.purchase_orders', $baselinePurchaseOrders + 1);
    }

    private function admin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function productAndWarehouse(int $companyId): array
    {
        $unit = Unit::where('company_id', $companyId)->firstOrFail();
        $warehouse = Warehouse::where('company_id', $companyId)->where('code', 'MAIN')->firstOrFail();
        $product = Product::create(['company_id' => $companyId, 'unit_id' => $unit->id, 'sku' => 'SALES-SKU-'.random_int(1000, 9999), 'name' => 'Sales Product', 'sale_price' => 120, 'purchase_price' => 70, 'valuation_method' => 'fifo', 'is_active' => true]);
        $this->postJson('/api/inventory/movements', ['type' => 'receipt', 'reference' => 'TEST-STOCK', 'lines' => [['product_id' => $product->id, 'to_warehouse_id' => $warehouse->id, 'quantity' => 10, 'unit_cost' => 70]]])->assertCreated();

        return [$product, $warehouse];
    }
}
