<?php

use App\Modules\Inventory\Http\Controllers\InventoryCatalogController;
use App\Modules\Inventory\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('inventory')->name('inventory.')->group(function () {
    Route::get('categories', [InventoryCatalogController::class, 'categories'])->name('categories.index');
    Route::post('categories', [InventoryCatalogController::class, 'storeCategory'])->name('categories.store');
    Route::put('categories/{category}', [InventoryCatalogController::class, 'updateCategory'])->name('categories.update');
    Route::get('units', [InventoryCatalogController::class, 'units'])->name('units.index');
    Route::post('units', [InventoryCatalogController::class, 'storeUnit'])->name('units.store');
    Route::put('units/{unit}', [InventoryCatalogController::class, 'updateUnit'])->name('units.update');
    Route::get('products', [InventoryCatalogController::class, 'products'])->name('products.index');
    Route::post('products', [InventoryCatalogController::class, 'storeProduct'])->name('products.store');
    Route::put('products/{product}', [InventoryCatalogController::class, 'updateProduct'])->name('products.update');
    Route::get('scan/{code}', [InventoryCatalogController::class, 'scan'])->name('scan');
    Route::get('warehouses', [InventoryCatalogController::class, 'warehouses'])->name('warehouses.index');
    Route::post('warehouses', [InventoryCatalogController::class, 'storeWarehouse'])->name('warehouses.store');
    Route::put('warehouses/{warehouse}', [InventoryCatalogController::class, 'updateWarehouse'])->name('warehouses.update');
    Route::post('locations', [InventoryCatalogController::class, 'storeLocation'])->name('locations.store');
    Route::put('locations/{location}', [InventoryCatalogController::class, 'updateLocation'])->name('locations.update');
    Route::get('movements', [StockController::class, 'movements'])->name('movements.index');
    Route::post('movements', [StockController::class, 'storeMovement'])->name('movements.store');
    Route::get('reports/stock-on-hand', [StockController::class, 'onHand'])->name('reports.on-hand');
    Route::get('reports/movements', [StockController::class, 'movements'])->name('reports.movements');
    Route::get('reports/valuation', [StockController::class, 'valuation'])->name('reports.valuation');
    Route::get('reorder-alerts', [StockController::class, 'alerts'])->name('reorder-alerts.index');
    Route::put('balances/{balance}/reorder', [StockController::class, 'updateReorder'])->name('balances.reorder.update');
});
