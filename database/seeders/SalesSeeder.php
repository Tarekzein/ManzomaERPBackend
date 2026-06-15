<?php

namespace Database\Seeders;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Models\FinanceContact;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Sales\Models\PriceList;
use App\Modules\Sales\Models\PurchaseOrder;
use App\Modules\Sales\Models\SalesContact;
use App\Modules\Sales\Models\SalesOrder;
use App\Modules\Sales\Models\SalesQuotation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

class SalesSeeder extends Seeder
{
    public function run(): void
    {
        Company::query()->where('is_active', true)->each(fn (Company $company) => $this->seedCompany($company));
    }

    private function seedCompany(Company $company): void
    {
        app(FinanceSetupService::class)->provision($company);
        app(InventorySetupService::class)->provision($company);

        $user = User::where('company_id', $company->id)->first();
        $unit = Unit::where('company_id', $company->id)->where('symbol', 'pcs')->firstOrFail();
        $warehouse = Warehouse::where('company_id', $company->id)->where('code', 'MAIN')->firstOrFail();

        $products = collect([
            ['sku' => 'ERP-CLOUD-001', 'name' => 'ERP Cloud License', 'description' => 'Annual ERP cloud subscription license.', 'sale_price' => 18000, 'purchase_price' => 9000],
            ['sku' => 'ERP-IMPL-001', 'name' => 'ERP Implementation Package', 'description' => 'Configuration, migration, and onboarding package.', 'sale_price' => 45000, 'purchase_price' => 22000],
        ])->map(fn (array $data) => Product::updateOrCreate(
            ['company_id' => $company->id, 'sku' => $data['sku']],
            $data + ['unit_id' => $unit->id, 'valuation_method' => 'average', 'is_active' => true],
        ));

        $financeCustomer = FinanceContact::updateOrCreate(
            ['company_id' => $company->id, 'email' => 'customer@seed.example'],
            ['type' => 'customer', 'name' => 'Nile Retail Group', 'phone' => '+201000000101', 'currency' => $company->currency],
        );
        $financeVendor = FinanceContact::updateOrCreate(
            ['company_id' => $company->id, 'email' => 'vendor@seed.example'],
            ['type' => 'vendor', 'name' => 'Cairo Technology Supplies', 'phone' => '+201000000202', 'currency' => $company->currency],
        );

        $customer = SalesContact::updateOrCreate(
            ['company_id' => $company->id, 'email' => 'customer@seed.example'],
            ['finance_contact_id' => $financeCustomer->id, 'type' => 'customer', 'name' => 'Nile Retail Group', 'phone' => '+201000000101', 'currency' => $company->currency, 'address' => ['city' => 'Cairo', 'country' => 'Egypt']],
        );
        $vendor = SalesContact::updateOrCreate(
            ['company_id' => $company->id, 'email' => 'vendor@seed.example'],
            ['finance_contact_id' => $financeVendor->id, 'type' => 'vendor', 'name' => 'Cairo Technology Supplies', 'phone' => '+201000000202', 'currency' => $company->currency, 'address' => ['city' => 'Cairo', 'country' => 'Egypt']],
        );

        $priceList = PriceList::updateOrCreate(
            ['company_id' => $company->id, 'name' => 'Strategic Customer Pricing'],
            ['contact_id' => $customer->id, 'starts_on' => now()->startOfYear(), 'ends_on' => now()->endOfYear(), 'is_active' => true],
        );
        foreach ($products as $product) {
            $priceList->items()->updateOrCreate(
                ['product_id' => $product->id],
                ['unit_price' => $product->sale_price, 'discount_percent' => 5],
            );
        }

        $quote = SalesQuotation::updateOrCreate(
            ['company_id' => $company->id, 'number' => 'SQ-SEED-001'],
            ['customer_id' => $customer->id, 'quote_date' => now()->toDateString(), 'valid_until' => now()->addDays(30)->toDateString(), 'status' => 'sent', 'currency' => $company->currency, 'notes' => 'Seeded quotation for the CRM and Sales demo workflow.', 'created_by' => $user?->id],
        );
        $this->seedLine($quote, $products[0], 20, 5, 14);
        $this->seedLine($quote, $products[1], 1, 0, 14);
        $this->recalculate($quote);

        $order = SalesOrder::updateOrCreate(
            ['company_id' => $company->id, 'number' => 'SO-SEED-001'],
            ['quotation_id' => $quote->id, 'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id, 'order_date' => now()->toDateString(), 'expected_ship_date' => now()->addDays(14)->toDateString(), 'status' => 'confirmed', 'currency' => $company->currency, 'notes' => 'Seeded confirmed sales order.', 'confirmed_at' => now(), 'created_by' => $user?->id],
        );
        $this->seedLine($order, $products[0], 10, 5, 14);
        $this->seedLine($order, $products[1], 1, 0, 14);
        $this->recalculate($order);

        $purchaseOrder = PurchaseOrder::updateOrCreate(
            ['company_id' => $company->id, 'number' => 'PO-SEED-001'],
            ['vendor_id' => $vendor->id, 'warehouse_id' => $warehouse->id, 'order_date' => now()->toDateString(), 'expected_receipt_date' => now()->addDays(10)->toDateString(), 'status' => 'confirmed', 'currency' => $company->currency, 'notes' => 'Seeded purchase order awaiting receipt.', 'confirmed_at' => now(), 'created_by' => $user?->id],
        );
        $this->seedLine($purchaseOrder, $products[0], 25, 0, 14, (float) $products[0]->purchase_price);
        $this->recalculate($purchaseOrder);
    }

    private function seedLine(Model $document, Product $product, float $quantity, float $discountPercent, float $taxPercent, ?float $unitPrice = null): void
    {
        $unitPrice ??= (float) $product->sale_price;
        $subtotal = round($quantity * $unitPrice, 4);
        $discount = round($subtotal * $discountPercent / 100, 4);
        $tax = round(($subtotal - $discount) * $taxPercent / 100, 4);

        $document->lines()->updateOrCreate(
            ['product_id' => $product->id],
            ['description' => $product->name, 'quantity' => $quantity, 'unit_price' => $unitPrice, 'discount_percent' => $discountPercent, 'tax_percent' => $taxPercent, 'subtotal' => $subtotal, 'discount_amount' => $discount, 'tax_amount' => $tax, 'total' => $subtotal - $discount + $tax],
        );
    }

    private function recalculate(Model $document): void
    {
        $lines = $document->lines()->get();
        $document->update(['subtotal' => $lines->sum('subtotal'), 'discount_total' => $lines->sum('discount_amount'), 'tax_total' => $lines->sum('tax_amount'), 'total' => $lines->sum('total')]);
    }
}
