<?php

use App\Modules\Reporting\Http\Controllers\ReportingController;
use Illuminate\Support\Facades\Route;

// Public shared report endpoint (no auth required)
Route::get('reporting/shared/{token}', [ReportingController::class, 'sharedReport'])->name('reporting.shared');

Route::middleware('auth:sanctum')->prefix('reporting')->name('reporting.')->group(function () {
    Route::get('catalog', [ReportingController::class, 'catalog'])->name('catalog');
    Route::get('dashboard', [ReportingController::class, 'dashboard'])->name('dashboard');
    Route::post('preview', [ReportingController::class, 'preview'])->name('preview');
    Route::get('prebuilt/{key}', [ReportingController::class, 'runPrebuilt'])->name('prebuilt.run');

    Route::get('reports', [ReportingController::class, 'reports'])->name('reports.index');
    Route::post('reports', [ReportingController::class, 'storeReport'])->name('reports.store');
    Route::put('reports/{report}', [ReportingController::class, 'updateReport'])->name('reports.update');
    Route::delete('reports/{report}', [ReportingController::class, 'deleteReport'])->name('reports.destroy');
    Route::post('reports/{report}/run', [ReportingController::class, 'runReport'])->name('reports.run');
    Route::get('reports/{report}/export/{format}', [ReportingController::class, 'export'])->name('reports.export');
    Route::post('reports/{report}/favorite', [ReportingController::class, 'toggleFavorite'])->name('reports.favorite');
    Route::post('reports/{report}/toggle-share', [ReportingController::class, 'toggleShare'])->name('reports.toggle-share');
    Route::post('reports/{report}/bust-cache', [ReportingController::class, 'bustCache'])->name('reports.bust-cache');
    Route::post('reports/{report}/run-comparison', [ReportingController::class, 'runWithComparison'])->name('reports.run-comparison');

    Route::get('widgets', [ReportingController::class, 'widgets'])->name('widgets.index');
    Route::post('widgets', [ReportingController::class, 'storeWidget'])->name('widgets.store');
    Route::put('widgets/{widget}', [ReportingController::class, 'updateWidget'])->name('widgets.update');
    Route::delete('widgets/{widget}', [ReportingController::class, 'deleteWidget'])->name('widgets.destroy');
    Route::post('widgets/reorder', [ReportingController::class, 'reorderWidgets'])->name('widgets.reorder');

    Route::get('schedules', [ReportingController::class, 'schedules'])->name('schedules.index');
    Route::post('schedules', [ReportingController::class, 'storeSchedule'])->name('schedules.store');
    Route::put('schedules/{schedule}', [ReportingController::class, 'updateSchedule'])->name('schedules.update');
    Route::delete('schedules/{schedule}', [ReportingController::class, 'deleteSchedule'])->name('schedules.destroy');
    Route::get('runs', [ReportingController::class, 'runs'])->name('runs.index');

    Route::get('alerts', [ReportingController::class, 'alerts'])->name('alerts.index');
    Route::post('alerts', [ReportingController::class, 'storeAlert'])->name('alerts.store');
    Route::put('alerts/{alert}', [ReportingController::class, 'updateAlert'])->name('alerts.update');
    Route::delete('alerts/{alert}', [ReportingController::class, 'deleteAlert'])->name('alerts.destroy');
});
