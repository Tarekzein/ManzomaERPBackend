<?php

use App\Modules\Platform\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/dashboard', DashboardController::class)
    ->name('dashboard');
