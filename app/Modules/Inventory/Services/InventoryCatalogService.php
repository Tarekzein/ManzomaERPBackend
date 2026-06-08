<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Policies\InventoryPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class InventoryCatalogService
{
    public function __construct(private readonly InventoryPolicy $policy) {}

    public function list(User $user, string $model, array $with = [])
    {
        return $model::with($with)->where('company_id', $this->policy->companyId($user))->orderBy('id')->get();
    }

    public function create(User $user, string $model, array $data): Model
    {
        $companyId = $this->policy->companyId($user, 'inventory.create');
        $this->validateRelations($companyId, $data);
        if ($model === Product::class) {
            $data['barcode'] ??= $this->barcode($companyId, $data['sku']);
            $data['qr_code'] ??= "product:{$data['sku']}";
        }

        return $model::create(['company_id' => $companyId] + $data);
    }

    public function updateProduct(User $user, Product $product, array $data): Product
    {
        $companyId = $this->policy->ensureOwned($user, $product);
        $this->validateRelations($companyId, $data);
        $product->update($data);

        return $product->load('category', 'unit', 'balances.warehouse');
    }

    public function update(User $user, Model $model, array $data): Model
    {
        $companyId = $this->policy->ensureOwned($user, $model);
        $this->validateRelations($companyId, $data);
        $model->update($data);

        return $model->refresh();
    }

    public function scan(User $user, string $code): Product
    {
        return Product::with('category', 'unit', 'balances.warehouse')
            ->where('company_id', $this->policy->companyId($user))
            ->where(fn ($query) => $query->where('barcode', $code)->orWhere('qr_code', $code)->orWhere('sku', $code))
            ->firstOrFail();
    }

    private function validateRelations(int $companyId, array $data): void
    {
        $relations = ['category_id' => ProductCategory::class, 'parent_id' => ProductCategory::class, 'unit_id' => Unit::class, 'warehouse_id' => Warehouse::class];
        foreach ($relations as $key => $model) {
            if (! empty($data[$key]) && ! $model::where('company_id', $companyId)->whereKey($data[$key])->exists()) {
                throw ValidationException::withMessages([$key => ['The selected record must belong to the company.']]);
            }
        }
        if (! empty($data['warehouse_id']) && ! empty($data['location_id']) && ! WarehouseLocation::where('company_id', $companyId)->where('warehouse_id', $data['warehouse_id'])->whereKey($data['location_id'])->exists()) {
            throw ValidationException::withMessages(['location_id' => ['The location must belong to the selected warehouse.']]);
        }
    }

    private function barcode(int $companyId, string $sku): string
    {
        return str_pad((string) $companyId, 4, '0', STR_PAD_LEFT).str_pad((string) abs(crc32($sku)), 9, '0', STR_PAD_LEFT);
    }
}
