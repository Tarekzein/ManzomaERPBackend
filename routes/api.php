<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\SystemController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [SystemController::class, 'health'])->name('health');
Route::get('/modules', [SystemController::class, 'modules'])->name('modules');
Route::get('/subscriptions/plans', [SubscriptionController::class, 'plans'])->name('subscriptions.plans');
Route::get('/subscriptions/features', [SubscriptionController::class, 'features'])->name('subscriptions.features');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/subscriptions/current', [SubscriptionController::class, 'current'])
        ->name('subscriptions.current');
});
