<?php

use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [SystemController::class, 'health'])->name('health');
Route::get('/modules', [SystemController::class, 'modules'])->name('modules');
Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans'])->name('subscriptions.plans');
Route::get('/subscriptions/features', [SubscriptionController::class, 'features'])->name('subscriptions.features');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/subscriptions/current', [SubscriptionController::class, 'current'])
        ->name('subscriptions.current');
});
