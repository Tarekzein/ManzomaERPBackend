<?php

use App\Modules\Subscriptions\Http\Controllers\CompanySubscriptionController;
use App\Modules\Subscriptions\Http\Controllers\SubscriptionAdminController;
use App\Modules\Subscriptions\Http\Controllers\SubscriptionCatalogController;
use App\Modules\Subscriptions\Http\Controllers\SubscriptionPaymentController;
use Illuminate\Support\Facades\Route;

Route::prefix('subscriptions')->name('subscriptions.')->group(function () {
    Route::get('/plans', [SubscriptionCatalogController::class, 'plans'])->name('plans');
    Route::get('/features', [SubscriptionCatalogController::class, 'features'])->name('features');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/current', [CompanySubscriptionController::class, 'current'])->name('current');
        Route::post('/subscribe', [CompanySubscriptionController::class, 'subscribe'])->name('subscribe');
        Route::post('/cancel', [CompanySubscriptionController::class, 'cancel'])->name('cancel');

        Route::post('/plans', [SubscriptionAdminController::class, 'storePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [SubscriptionAdminController::class, 'updatePlan'])->name('plans.update');
        Route::put('/plans/{plan}/features', [SubscriptionAdminController::class, 'assignFeatures'])->name('plans.features.update');
        Route::put('/plans/{plan}/features/{feature}', [SubscriptionAdminController::class, 'savePlanFeature'])->name('plans.features.save');
        Route::delete('/plans/{plan}/features/{feature}', [SubscriptionAdminController::class, 'removePlanFeature'])->name('plans.features.remove');
        Route::post('/features', [SubscriptionAdminController::class, 'storeFeature'])->name('features.store');
        Route::put('/features/{feature}', [SubscriptionAdminController::class, 'updateFeature'])->name('features.update');
    });
});

Route::prefix('payments')->name('payments.')->group(function () {
    Route::get('/{reference}/status', [SubscriptionPaymentController::class, 'status'])->name('status');
    Route::post('/{reference}/mock-result', [SubscriptionPaymentController::class, 'mockResult'])->name('mock-result');
    Route::post('/paymob/callback', [SubscriptionPaymentController::class, 'callback'])->name('paymob.callback');
});
