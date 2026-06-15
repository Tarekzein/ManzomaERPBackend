<?php

use App\Modules\Sales\Http\Controllers\SalesController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('sales')->name('sales.')->group(function () {
    Route::get('contacts', [SalesController::class, 'contacts'])->name('contacts.index');
    Route::post('contacts', [SalesController::class, 'storeContact'])->name('contacts.store');
    Route::put('contacts/{contact}', [SalesController::class, 'updateContact'])->name('contacts.update');
    Route::get('price-lists', [SalesController::class, 'priceLists'])->name('price-lists.index');
    Route::post('price-lists', [SalesController::class, 'storePriceList'])->name('price-lists.store');
    Route::put('price-lists/{priceList}', [SalesController::class, 'updatePriceList'])->name('price-lists.update');
    Route::get('quotations', [SalesController::class, 'quotations'])->name('quotations.index');
    Route::post('quotations', [SalesController::class, 'storeQuotation'])->name('quotations.store');
    Route::put('quotations/{quotation}', [SalesController::class, 'updateQuotation'])->name('quotations.update');
    Route::post('quotations/{quotation}/convert', [SalesController::class, 'convertQuotation'])->name('quotations.convert');
    Route::get('quotations/{quotation}/pdf', [SalesController::class, 'quotationPdf'])->name('quotations.pdf');
    Route::get('orders', [SalesController::class, 'orders'])->name('orders.index');
    Route::post('orders', [SalesController::class, 'storeOrder'])->name('orders.store');
    Route::put('orders/{order}', [SalesController::class, 'updateOrder'])->name('orders.update');
    Route::post('orders/{order}/confirm', [SalesController::class, 'confirmOrder'])->name('orders.confirm');
    Route::post('orders/{order}/ship', [SalesController::class, 'shipOrder'])->name('orders.ship');
    Route::post('orders/{order}/invoice', [SalesController::class, 'invoiceOrder'])->name('orders.invoice');
    Route::post('orders/{order}/close', [SalesController::class, 'closeOrder'])->name('orders.close');
    Route::get('orders/{order}/invoice-pdf', [SalesController::class, 'invoicePdf'])->name('orders.invoice-pdf');
    Route::get('orders/{order}/delivery-note', [SalesController::class, 'deliveryNotePdf'])->name('orders.delivery-note');
    Route::get('purchase-orders', [SalesController::class, 'purchaseOrders'])->name('purchase-orders.index');
    Route::post('purchase-orders', [SalesController::class, 'storePurchaseOrder'])->name('purchase-orders.store');
    Route::put('purchase-orders/{purchaseOrder}', [SalesController::class, 'updatePurchaseOrder'])->name('purchase-orders.update');
    Route::post('purchase-orders/{purchaseOrder}/confirm', [SalesController::class, 'confirmPurchaseOrder'])->name('purchase-orders.confirm');
    Route::post('purchase-orders/{purchaseOrder}/receive', [SalesController::class, 'receivePurchaseOrder'])->name('goods-receipts.store');
    Route::post('purchase-orders/{purchaseOrder}/match', [SalesController::class, 'matchPurchaseOrder'])->name('match.store');
    Route::get('purchase-orders/{purchaseOrder}/pdf', [SalesController::class, 'purchaseOrderPdf'])->name('purchase-orders.pdf');
    Route::get('reports/{report}', [SalesController::class, 'report'])->name('reports.show');
});
