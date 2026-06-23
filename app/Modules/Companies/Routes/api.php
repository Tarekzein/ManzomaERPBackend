<?php

use App\Modules\Companies\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/companies', [CompanyController::class, 'index'])
    ->name('companies.index');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/company', [CompanyController::class, 'current'])->name('company.current');
    Route::match(['put', 'post'], '/company/setup', [CompanyController::class, 'setup'])->name('company.setup');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::post('/companies/{company}/suspend', [CompanyController::class, 'suspend'])->name('companies.suspend');
    Route::post('/companies/{company}/reactivate', [CompanyController::class, 'reactivate'])->name('companies.reactivate');
    Route::get('/companies/{company}/export', [CompanyController::class, 'export'])->name('companies.export');
    Route::delete('/companies/{company}', [CompanyController::class, 'erase'])->name('companies.erase');
});
