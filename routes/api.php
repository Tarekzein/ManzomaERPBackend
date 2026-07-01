<?php

use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [SystemController::class, 'health'])->name('health');
Route::get('/modules', [SystemController::class, 'modules'])->name('modules');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/system/health', [SystemController::class, 'detailedHealth'])->name('system.health');
});
