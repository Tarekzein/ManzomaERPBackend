<?php

use App\Modules\CustomModules\Http\Controllers\CustomModuleController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('custom-modules')->name('custom-modules.')->group(function () {
    Route::get('/', [CustomModuleController::class, 'index'])->name('index');
    Route::post('/', [CustomModuleController::class, 'store'])->name('store');
    Route::put('/{module}', [CustomModuleController::class, 'update'])->name('update');
    Route::post('/{module}/install', [CustomModuleController::class, 'install'])->name('install');
    Route::patch('/{module}/status', [CustomModuleController::class, 'status'])->name('status');
    Route::delete('/{module}/install', [CustomModuleController::class, 'uninstall'])->name('uninstall');
});
