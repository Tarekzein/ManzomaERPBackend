<?php

use App\Modules\Reporting\Http\Controllers\ReportingController;
use Illuminate\Support\Facades\Route;

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
});
