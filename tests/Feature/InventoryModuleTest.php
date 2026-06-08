<?php

namespace Tests\Feature;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InventoryModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_barcode_receipt_and_stock_reports_work(): void
    {
        $admin = $this->companyAdmin();
        [$warehouse, $location, $unit] = $this->inventorySetup($admin);
        $product = $this->product($unit->id, 'average');

        $this->getJson("/api/inventory/scan/{$product['barcode']}")
            ->assertOk()->assertJsonPath('data.sku', 'SKU-001');

        $this->postJson('/api/inventory/movements', [
            'type' => 'receipt', 'reference' => 'PO-001',
            'lines' => [['product_id' => $product['id'], 'to_warehouse_id' => $warehouse->id, 'to_location_id' => $location->id, 'quantity' => 10, 'unit_cost' => 25]],
        ])->assertCreated()->assertJsonPath('data.type', 'receipt');

        $this->getJson('/api/inventory/reports/stock-on-hand')->assertOk()
            ->assertJsonPath('data.0.quantity', '10.0000');
        $this->getJson('/api/inventory/reports/valuation')->assertOk()
            ->assertJsonPath('data.total_value', 250);
    }

    public function test_transfer_preserves_total_stock_and_issue_uses_fifo_layers(): void
    {
        $admin = $this->companyAdmin();
        [$main, $mainLocation, $unit] = $this->inventorySetup($admin);
        $second = Warehouse::create(['company_id' => $admin->company_id, 'code' => 'SECOND', 'name' => 'Second']);
        $secondLocation = WarehouseLocation::create(['company_id' => $admin->company_id, 'warehouse_id' => $second->id, 'code' => 'DEFAULT', 'name' => 'Default']);
        $product = $this->product($unit->id, 'fifo');
        $this->assertDatabaseHas('products', ['id' => $product['id'], 'valuation_method' => 'fifo']);

        foreach ([[5, 10, now()->subDays(2)->toDateTimeString()], [5, 20, now()->subDay()->toDateTimeString()]] as [$quantity, $cost, $occurredAt]) {
            $this->postJson('/api/inventory/movements', ['type' => 'receipt', 'occurred_at' => $occurredAt, 'lines' => [['product_id' => $product['id'], 'to_warehouse_id' => $main->id, 'to_location_id' => $mainLocation->id, 'quantity' => $quantity, 'unit_cost' => $cost]]])->assertCreated();
        }
        $this->postJson('/api/inventory/movements', ['type' => 'transfer', 'lines' => [['product_id' => $product['id'], 'from_warehouse_id' => $main->id, 'from_location_id' => $mainLocation->id, 'to_warehouse_id' => $second->id, 'to_location_id' => $secondLocation->id, 'quantity' => 2]]])->assertCreated();
        $issue = $this->postJson('/api/inventory/movements', ['type' => 'issue', 'lines' => [['product_id' => $product['id'], 'from_warehouse_id' => $main->id, 'from_location_id' => $mainLocation->id, 'quantity' => 4]]])
            ->assertCreated();
        $issue->assertJsonPath('data.lines.0.unit_cost', '12.5000');

        $this->assertSame(6.0, (float) StockBalance::where('product_id', $product['id'])->sum('quantity'));
        $this->getJson('/api/inventory/reports/valuation')->assertOk()->assertJsonPath('data.total_value', 100);
    }

    public function test_reorder_alerts_and_write_off_reason_are_enforced(): void
    {
        $admin = $this->companyAdmin();
        [$warehouse, $location, $unit] = $this->inventorySetup($admin);
        $product = $this->product($unit->id, 'average');
        $this->postJson('/api/inventory/movements', ['type' => 'receipt', 'lines' => [['product_id' => $product['id'], 'to_warehouse_id' => $warehouse->id, 'to_location_id' => $location->id, 'quantity' => 5, 'unit_cost' => 10]]])->assertCreated();
        $balance = StockBalance::where('product_id', $product['id'])->firstOrFail();
        $this->putJson("/api/inventory/balances/{$balance->id}/reorder", ['reorder_point' => 3, 'reorder_quantity' => 10])->assertOk();

        $payload = ['type' => 'write_off', 'lines' => [['product_id' => $product['id'], 'from_warehouse_id' => $warehouse->id, 'from_location_id' => $location->id, 'quantity' => 3]]];
        $this->postJson('/api/inventory/movements', $payload)->assertUnprocessable();
        $this->postJson('/api/inventory/movements', $payload + ['reason_code' => 'DAMAGED'])->assertCreated();
        $this->getJson('/api/inventory/reorder-alerts')->assertOk()->assertJsonPath('data.0.quantity', '2.0000');
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $admin->id]);
    }

    public function test_insufficient_stock_and_cross_company_access_are_rejected(): void
    {
        $admin = $this->companyAdmin();
        [$warehouse, $location, $unit] = $this->inventorySetup($admin);
        $product = $this->product($unit->id, 'average');
        $this->postJson('/api/inventory/movements', ['type' => 'issue', 'lines' => [['product_id' => $product['id'], 'from_warehouse_id' => $warehouse->id, 'from_location_id' => $location->id, 'quantity' => 1]]])->assertUnprocessable();

        $foreignCompany = Company::create(['name' => 'Foreign Company', 'plan' => 'basic']);
        $this->postJson('/api/inventory/products', [
            'unit_id' => Unit::create(['company_id' => $foreignCompany->id, 'name' => 'Foreign', 'symbol' => 'foreign'])->id,
            'sku' => 'FORBIDDEN', 'name' => 'Foreign', 'sale_price' => 1, 'purchase_price' => 1, 'valuation_method' => 'average',
        ])->assertUnprocessable();
    }

    public function test_lifo_and_average_cost_strategies_calculate_issue_costs(): void
    {
        $admin = $this->companyAdmin();
        [$warehouse, $location, $unit] = $this->inventorySetup($admin);

        foreach ([['LIFO-001', 'lifo', 20.0], ['AVG-001', 'average', 15.0]] as [$sku, $method, $expected]) {
            $product = $this->postJson('/api/inventory/products', [
                'unit_id' => $unit->id, 'sku' => $sku, 'name' => $sku,
                'sale_price' => 30, 'purchase_price' => 10, 'valuation_method' => $method,
            ])->assertCreated()->json('data');
            foreach ([[5, 10], [5, 20]] as [$quantity, $cost]) {
                $this->postJson('/api/inventory/movements', ['type' => 'receipt', 'lines' => [['product_id' => $product['id'], 'to_warehouse_id' => $warehouse->id, 'to_location_id' => $location->id, 'quantity' => $quantity, 'unit_cost' => $cost]]])->assertCreated();
            }
            $this->postJson('/api/inventory/movements', ['type' => 'issue', 'lines' => [['product_id' => $product['id'], 'from_warehouse_id' => $warehouse->id, 'from_location_id' => $location->id, 'quantity' => 2]]])
                ->assertCreated()->assertJsonPath('data.lines.0.unit_cost', number_format($expected, 4, '.', ''));
        }
    }

    private function companyAdmin(): User
    {
        $this->seed(DatabaseSeeder::class);
        $admin = User::where('email', 'company.admin@example.com')->firstOrFail();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function inventorySetup(User $admin): array
    {
        $warehouse = Warehouse::where('company_id', $admin->company_id)->where('code', 'MAIN')->firstOrFail();

        return [$warehouse, $warehouse->locations()->firstOrFail(), Unit::where('company_id', $admin->company_id)->where('symbol', 'pcs')->firstOrFail()];
    }

    private function product(int $unitId, string $valuation): array
    {
        return $this->postJson('/api/inventory/products', [
            'unit_id' => $unitId, 'sku' => 'SKU-001', 'name' => 'Product One',
            'sale_price' => 50, 'purchase_price' => 25, 'valuation_method' => $valuation,
        ])->assertCreated()->json('data');
    }
}
