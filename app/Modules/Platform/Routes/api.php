<?php

use App\Modules\Platform\Http\Controllers\ComplianceController;
use App\Modules\Platform\Http\Controllers\DashboardController;
use App\Modules\Platform\Http\Controllers\GlobalSearchController;
use App\Modules\Platform\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/dashboard', DashboardController::class)
    ->name('dashboard');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/audit-logs', [ComplianceController::class, 'audits'])->name('audit-logs.index');
    Route::get('/usage', [ComplianceController::class, 'usage'])->name('usage.index');
    Route::get('/search', GlobalSearchController::class)->name('search');
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::put('/webhooks/{endpoint}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{endpoint}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::get('/webhook-deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
    Route::post('/webhook-deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->name('webhooks.retry');
});
