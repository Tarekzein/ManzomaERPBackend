<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\InventoryCostLayer;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockBalance;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Policies\InventoryPolicy;
use App\Modules\Inventory\Services\Valuation\ValuationStrategyFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockMovementService
{
    public function __construct(private readonly InventoryPolicy $policy, private readonly ValuationStrategyFactory $valuations, private readonly ReorderAlertService $alerts) {}

    public function list(User $user)
    {
        return StockMovement::with('lines.product')->where('company_id', $this->policy->companyId($user))->latest('occurred_at')->get();
    }

    public function create(User $user, array $data): StockMovement
    {
        $companyId = $this->policy->companyId($user, 'inventory.create');

        return DB::transaction(function () use ($user, $data, $companyId) {
            $movement = StockMovement::create([
                'company_id' => $companyId, 'number' => 'SM-'.now()->format('YmdHis').'-'.random_int(100, 999),
                'type' => $data['type'], 'reason_code' => $data['reason_code'] ?? null, 'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null, 'occurred_at' => $data['occurred_at'] ?? now(), 'created_by' => $user->id,
            ]);
            foreach ($data['lines'] as $line) {
                $product = Product::where('company_id', $companyId)->findOrFail($line['product_id']);
                $this->validateEndpoints($companyId, $data['type'], $line);
                $quantity = (float) $line['quantity'];
                $unitCost = (float) ($line['unit_cost'] ?? $product->purchase_price);
                if (in_array($data['type'], ['issue', 'adjust_out', 'write_off', 'transfer'], true)) {
                    $source = $this->balance($companyId, $product->id, $line['from_warehouse_id'], $line['from_location_id'] ?? null);
                    if ((float) $source->quantity < $quantity) {
                        throw ValidationException::withMessages(['quantity' => ['Insufficient stock for this movement.']]);
                    }
                    $unitCost = $this->valuations->make($product->valuation_method)->issue($source, $quantity);
                    $source->decrement('quantity', $quantity);
                    $this->alerts->evaluate($source->refresh());
                }
                $movementLine = $movement->lines()->create($line + ['unit_cost' => $unitCost, 'total_cost' => round($quantity * $unitCost, 4)]);
                if (in_array($data['type'], ['receipt', 'adjust_in', 'transfer'], true)) {
                    $target = $this->balance($companyId, $product->id, $line['to_warehouse_id'], $line['to_location_id'] ?? null);
                    $oldValue = (float) $target->quantity * (float) $target->average_cost;
                    $newQuantity = (float) $target->quantity + $quantity;
                    $target->update(['quantity' => $newQuantity, 'average_cost' => $newQuantity > 0 ? round(($oldValue + $quantity * $unitCost) / $newQuantity, 4) : 0]);
                    InventoryCostLayer::create(['company_id' => $companyId, 'product_id' => $product->id, 'warehouse_id' => $target->warehouse_id, 'location_id' => $target->location_id, 'source_movement_line_id' => $movementLine->id, 'original_quantity' => $quantity, 'remaining_quantity' => $quantity, 'unit_cost' => $unitCost, 'received_at' => $movement->occurred_at]);
                    $this->alerts->evaluate($target->refresh());
                }
            }

            return $movement->load('lines.product');
        });
    }

    private function balance(int $companyId, int $productId, int $warehouseId, ?int $locationId): StockBalance
    {
        return StockBalance::firstOrCreate(['company_id' => $companyId, 'product_id' => $productId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId], ['quantity' => 0, 'average_cost' => 0, 'reorder_point' => 0, 'reorder_quantity' => 0]);
    }

    private function validateEndpoints(int $companyId, string $type, array $line): void
    {
        foreach (['from_warehouse_id', 'to_warehouse_id'] as $field) {
            if (! empty($line[$field])) {
                Warehouse::where('company_id', $companyId)->findOrFail($line[$field]);
            }
        }
        foreach (['from_location_id', 'to_location_id'] as $field) {
            if (! empty($line[$field])) {
                WarehouseLocation::where('company_id', $companyId)->findOrFail($line[$field]);
            }
        }
        if (in_array($type, ['issue', 'adjust_out', 'write_off', 'transfer'], true) && empty($line['from_warehouse_id'])) {
            throw ValidationException::withMessages(['from_warehouse_id' => ['A source warehouse is required.']]);
        }
        if (in_array($type, ['receipt', 'adjust_in', 'transfer'], true) && empty($line['to_warehouse_id'])) {
            throw ValidationException::withMessages(['to_warehouse_id' => ['A destination warehouse is required.']]);
        }
        foreach ([['from_location_id', 'from_warehouse_id'], ['to_location_id', 'to_warehouse_id']] as [$location, $warehouse]) {
            if (! empty($line[$location]) && ! WarehouseLocation::where('company_id', $companyId)->where('warehouse_id', $line[$warehouse])->whereKey($line[$location])->exists()) {
                throw ValidationException::withMessages([$location => ['The location must belong to its selected warehouse.']]);
            }
        }
    }
}
