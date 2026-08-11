<?php

use App\Modules\Platform\Http\Controllers\ComplianceController;
use App\Modules\Platform\Http\Controllers\DashboardController;
use App\Modules\Platform\Http\Controllers\GlobalSearchController;
use App\Modules\Platform\Http\Controllers\SocialInboxController;
use App\Modules\Platform\Http\Controllers\SocialInsightsController;
use App\Modules\Platform\Http\Controllers\TranslationController;
use App\Modules\Platform\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->get('/dashboard', DashboardController::class)
    ->name('dashboard');

// Cross-platform social performance (Meta + TikTok) built from local data.
Route::middleware('auth:sanctum')->prefix('social')->name('social.')->group(function () {
    Route::get('insights', [SocialInsightsController::class, 'summary'])->name('insights');
    Route::get('campaigns/{platform}/{campaignId}/leads', [SocialInsightsController::class, 'campaignLeads'])
        ->name('campaigns.leads');

    // Inbox: comments and messages from every connected platform.
    Route::get('inbox', [SocialInboxController::class, 'index'])->name('inbox.index');
    Route::get('inbox/summary', [SocialInboxController::class, 'summary'])->name('inbox.summary');
    Route::post('inbox/pages/{page}/import', [SocialInboxController::class, 'importComments'])->name('inbox.import');
    Route::post('inbox/{interaction}/task', [SocialInboxController::class, 'convertToTask'])->name('inbox.task');
    Route::put('inbox/{interaction}/status', [SocialInboxController::class, 'updateStatus'])->name('inbox.status');
    Route::post('inbox/{interaction}/reply', [SocialInboxController::class, 'reply'])->name('inbox.reply');

    Route::post('publish', [SocialInboxController::class, 'publish'])->name('publish');
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/audit-logs', [ComplianceController::class, 'audits'])->name('audit-logs.index');
    Route::get('/usage', [ComplianceController::class, 'usage'])->name('usage.index');
    Route::get('/search', GlobalSearchController::class)->name('search');
    Route::post('/translations/batch', TranslationController::class)->name('translations.batch');
    Route::get('/webhooks', [WebhookController::class, 'index'])->name('webhooks.index');
    Route::post('/webhooks', [WebhookController::class, 'store'])->name('webhooks.store');
    Route::put('/webhooks/{endpoint}', [WebhookController::class, 'update'])->name('webhooks.update');
    Route::delete('/webhooks/{endpoint}', [WebhookController::class, 'destroy'])->name('webhooks.destroy');
    Route::get('/webhook-deliveries', [WebhookController::class, 'deliveries'])->name('webhooks.deliveries');
    Route::post('/webhook-deliveries/{delivery}/retry', [WebhookController::class, 'retry'])->name('webhooks.retry');
});
