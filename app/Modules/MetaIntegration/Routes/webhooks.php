<?php

use App\Modules\MetaIntegration\Http\Controllers\MetaLeadWebhookController;
use Illuminate\Support\Facades\Route;

/**
 * Public Meta webhook endpoints. They are unauthenticated by design and are
 * trusted only after the X-Hub-Signature-256 check, so they sit outside the
 * per-tenant API rate limiter: Meta delivers in bursts and a 429 would drop
 * lead and message events.
 */
Route::prefix('meta/webhooks')
    ->name('meta.webhooks.')
    ->withoutMiddleware(['throttle:erp-api'])
    ->middleware('throttle:meta-webhooks')
    ->group(function () {
        Route::get('leadgen', [MetaLeadWebhookController::class, 'verify'])->name('leadgen.verify');
        Route::post('leadgen', [MetaLeadWebhookController::class, 'receive'])->name('leadgen.receive');
    });
