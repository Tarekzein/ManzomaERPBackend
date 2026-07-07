<?php

use App\Modules\MetaIntegration\Http\Controllers\MetaLeadWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('meta/webhooks')->name('meta.webhooks.')->group(function () {
    Route::get('leadgen', [MetaLeadWebhookController::class, 'verify'])->name('leadgen.verify');
    Route::post('leadgen', [MetaLeadWebhookController::class, 'receive'])->name('leadgen.receive');
});
