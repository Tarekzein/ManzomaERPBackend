<?php

use App\Modules\POS\Http\Controllers\PosCatalogController;
use App\Modules\POS\Http\Controllers\PosHoldController;
use App\Modules\POS\Http\Controllers\PosRegisterController;
use App\Modules\POS\Http\Controllers\PosReportController;
use App\Modules\POS\Http\Controllers\PosReturnController;
use App\Modules\POS\Http\Controllers\PosTerminalController;
use App\Modules\POS\Http\Controllers\PosSaleController;
use App\Modules\POS\Http\Controllers\PosShiftController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('pos')->name('pos.')->group(function () {
    Route::get('/bootstrap', [PosCatalogController::class, 'bootstrap'])->name('bootstrap');
    Route::get('/catalog', [PosCatalogController::class, 'index'])->name('catalog.index');
    Route::get('/catalog/barcode/{barcode}', [PosCatalogController::class, 'barcode'])->name('catalog.barcode');
    Route::post('/price', [PosSaleController::class, 'price'])->name('price');

    Route::post('/sales', [PosSaleController::class, 'store'])->name('sales.store');
    Route::get('/sales', [PosSaleController::class, 'index'])->name('sales.index');
    Route::get('/sales/{sale}', [PosSaleController::class, 'show'])->name('sales.show');
    Route::get('/sales/{sale}/receipt', [PosSaleController::class, 'receipt'])->name('sales.receipt');

    // Returns, refunds and voids. A finalized sale is corrected, never edited.
    Route::post('/sales/{sale}/returns', [PosReturnController::class, 'store'])->name('sales.returns');
    Route::post('/sales/{sale}/void', [PosReturnController::class, 'void'])->name('sales.void');
    Route::get('/returns', [PosReturnController::class, 'index'])->name('returns.index');

    // Parked carts.
    Route::get('/holds', [PosHoldController::class, 'index'])->name('holds.index');
    Route::post('/holds', [PosHoldController::class, 'store'])->name('holds.store');
    Route::delete('/holds/{hold}', [PosHoldController::class, 'destroy'])->name('holds.destroy');

    // Card terminal: intent starts an attempt. Browser confirmation is only
    // for an attended manual terminal and requires an assigned supervisor;
    // integrated providers must use a signed server-side confirmation path.
    Route::post('/terminal/intent', [PosTerminalController::class, 'intent'])->name('terminal.intent');
    Route::post('/terminal/confirm', [PosTerminalController::class, 'confirm'])->name('terminal.confirm');

    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/summary', [PosReportController::class, 'summary'])->name('summary');
        Route::get('/shifts', [PosReportController::class, 'shifts'])->name('shifts');
        Route::get('/tenders', [PosReportController::class, 'tenders'])->name('tenders');
        Route::get('/products', [PosReportController::class, 'products'])->name('products');
        Route::get('/taxes', [PosReportController::class, 'taxes'])->name('taxes');
        Route::get('/margins', [PosReportController::class, 'margins'])->name('margins');
    });

    Route::get('/registers', [PosRegisterController::class, 'index'])->name('registers.index');
    Route::post('/registers', [PosRegisterController::class, 'store'])->name('registers.store');
    Route::put('/registers/{register}', [PosRegisterController::class, 'update'])->name('registers.update');
    Route::post('/registers/{register}/assignments', [PosRegisterController::class, 'assign'])->name('registers.assign');
    Route::delete('/registers/{register}/assignments/{assignment}', [PosRegisterController::class, 'unassign'])
        ->name('registers.unassign');

    Route::get('/shifts/current', [PosShiftController::class, 'current'])->name('shifts.current');
    Route::post('/shifts/open', [PosShiftController::class, 'open'])->name('shifts.open');
    Route::post('/shifts/{shift}/cash-movements', [PosShiftController::class, 'cashMovement'])->name('shifts.cash');
    Route::post('/shifts/{shift}/close', [PosShiftController::class, 'close'])->name('shifts.close');
});
