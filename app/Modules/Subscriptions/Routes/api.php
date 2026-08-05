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
        Route::get('/payments', [CompanySubscriptionController::class, 'payments'])->name('payments');
        Route::get('/payments/{reference}', [CompanySubscriptionController::class, 'payment'])->name('payments.show');
        Route::post('/subscribe', [CompanySubscriptionController::class, 'subscribe'])->name('subscribe');
        Route::post('/checkout', [CompanySubscriptionController::class, 'checkout'])->name('checkout');
        Route::post('/renew', [CompanySubscriptionController::class, 'renew'])->name('renew');
        Route::post('/cancel', [CompanySubscriptionController::class, 'cancel'])->name('cancel');
        Route::post('/resume', [CompanySubscriptionController::class, 'resume'])->name('resume');
        Route::post('/auto-renew', [CompanySubscriptionController::class, 'autoRenew'])->name('auto-renew');
        Route::delete('/payment-method', [CompanySubscriptionController::class, 'forgetPaymentMethod'])->name('payment-method.forget');

        Route::get('/admin/billing', [SubscriptionAdminController::class, 'billingOverview'])->name('admin.billing');
        Route::post('/admin/companies/{company}/renew-without-payment', [SubscriptionAdminController::class, 'renewCompanyWithoutPayment'])->name('admin.companies.renew-without-payment');

        Route::post('/plans', [SubscriptionAdminController::class, 'storePlan'])->name('plans.store');
        Route::put('/plans/{plan}', [SubscriptionAdminController::class, 'updatePlan'])->name('plans.update');
        Route::put('/plans/{plan}/features', [SubscriptionAdminController::class, 'assignFeatures'])->name('plans.features.update');
        Route::put('/plans/{plan}/features/{feature}', [SubscriptionAdminController::class, 'savePlanFeature'])->name('plans.features.save');
        Route::delete('/plans/{plan}/features/{feature}', [SubscriptionAdminController::class, 'removePlanFeature'])->name('plans.features.remove');
        Route::get('/plans/{plan}/promotions', [SubscriptionAdminController::class, 'promotions'])->name('plans.promotions.index');
        Route::post('/plans/{plan}/promotions', [SubscriptionAdminController::class, 'storePromotion'])->name('plans.promotions.store');
        Route::put('/plans/{plan}/promotions/{promotion}', [SubscriptionAdminController::class, 'updatePromotion'])->name('plans.promotions.update');
        Route::delete('/plans/{plan}/promotions/{promotion}', [SubscriptionAdminController::class, 'deletePromotion'])->name('plans.promotions.delete');
        Route::post('/features', [SubscriptionAdminController::class, 'storeFeature'])->name('features.store');
        Route::put('/features/{feature}', [SubscriptionAdminController::class, 'updateFeature'])->name('features.update');
    });
});

Route::prefix('payments')->name('payments.')->group(function () {
    Route::get('/{reference}/status', [SubscriptionPaymentController::class, 'status'])->name('status');
    Route::post('/{reference}/checkout', [SubscriptionPaymentController::class, 'retryCheckout'])->name('checkout.retry');
    Route::post('/{reference}/session', [SubscriptionPaymentController::class, 'session'])->name('session');
    Route::post('/{reference}/mock-result', [SubscriptionPaymentController::class, 'mockResult'])->name('mock-result');

    // Paymob transaction/token webhook. Unauthenticated by design: the payload
    // is trusted only after its HMAC signature is verified.
    Route::post('/paymob/callback', [SubscriptionPaymentController::class, 'callback'])->name('paymob.callback');
    // Where the customer's browser is sent back to after paying.
    Route::get('/paymob/callback', [SubscriptionPaymentController::class, 'redirectResult'])->name('paymob.redirect');
});
