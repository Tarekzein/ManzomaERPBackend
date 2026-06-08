<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Requests\InventoryRequest;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Unit;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Models\WarehouseLocation;
use App\Modules\Inventory\Services\InventoryCatalogService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class InventoryCatalogController extends Controller
{
    public function __construct(private readonly InventoryCatalogService $catalog) {}

    public function categories(Request $request)
    {
        return ApiResponse::success($this->catalog->list($request->user(), ProductCategory::class));
    }

    public function storeCategory(InventoryRequest $request)
    {
        return ApiResponse::success($this->catalog->create($request->user(), ProductCategory::class, $request->validated()), 'Category created', status: 201);
    }

    public function updateCategory(InventoryRequest $request, ProductCategory $category)
    {
        return ApiResponse::success($this->catalog->update($request->user(), $category, $request->validated()), 'Category updated');
    }

    public function units(Request $request)
    {
        return ApiResponse::success($this->catalog->list($request->user(), Unit::class));
    }

    public function storeUnit(InventoryRequest $request)
    {
        return ApiResponse::success($this->catalog->create($request->user(), Unit::class, $request->validated()), 'Unit created', status: 201);
    }

    public function updateUnit(InventoryRequest $request, Unit $unit)
    {
        return ApiResponse::success($this->catalog->update($request->user(), $unit, $request->validated()), 'Unit updated');
    }

    public function products(Request $request)
    {
        return ApiResponse::success($this->catalog->list($request->user(), Product::class, ['category', 'unit', 'balances.warehouse']));
    }

    public function storeProduct(InventoryRequest $request)
    {
        return ApiResponse::success($this->catalog->create($request->user(), Product::class, $request->validated()), 'Product created', status: 201);
    }

    public function updateProduct(InventoryRequest $request, Product $product)
    {
        return ApiResponse::success($this->catalog->updateProduct($request->user(), $product, $request->validated()), 'Product updated');
    }

    public function scan(Request $request, string $code)
    {
        return ApiResponse::success($this->catalog->scan($request->user(), $code), 'Product scanned');
    }

    public function warehouses(Request $request)
    {
        return ApiResponse::success($this->catalog->list($request->user(), Warehouse::class, ['locations']));
    }

    public function storeWarehouse(InventoryRequest $request)
    {
        return ApiResponse::success($this->catalog->create($request->user(), Warehouse::class, $request->validated()), 'Warehouse created', status: 201);
    }

    public function updateWarehouse(InventoryRequest $request, Warehouse $warehouse)
    {
        return ApiResponse::success($this->catalog->update($request->user(), $warehouse, $request->validated()), 'Warehouse updated');
    }

    public function storeLocation(InventoryRequest $request)
    {
        return ApiResponse::success($this->catalog->create($request->user(), WarehouseLocation::class, $request->validated()), 'Location created', status: 201);
    }

    public function updateLocation(InventoryRequest $request, WarehouseLocation $location)
    {
        return ApiResponse::success($this->catalog->update($request->user(), $location, $request->validated()), 'Location updated');
    }
}
