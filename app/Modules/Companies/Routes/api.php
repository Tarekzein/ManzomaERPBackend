<?php

use App\Modules\Companies\Http\Controllers\CompanyController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/companies', [CompanyController::class, 'index'])
    ->name('companies.index');
