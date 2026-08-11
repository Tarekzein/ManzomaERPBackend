<?php

use App\Modules\TikTokIntegration\Http\Controllers\TikTokAudienceController;
use App\Modules\TikTokIntegration\Http\Controllers\TikTokConnectionController;
use App\Modules\TikTokIntegration\Http\Controllers\TikTokEventMappingController;
use App\Modules\TikTokIntegration\Http\Controllers\TikTokLeadFormController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('tiktok')->name('tiktok.')->group(function () {
    Route::get('connection', [TikTokConnectionController::class, 'show'])->name('connection.show');
    Route::delete('connection', [TikTokConnectionController::class, 'destroy'])->name('connection.destroy');
    Route::post('connection/app-credentials', [TikTokConnectionController::class, 'storeAppCredentials'])->name('connection.app-credentials');
    Route::put('settings', [TikTokConnectionController::class, 'saveSettings'])->name('settings');

    // Per-company app setup: the values each tenant pastes into their app.
    Route::get('setup', [TikTokConnectionController::class, 'setup'])->name('setup');
    Route::post('setup/verify-token', [TikTokConnectionController::class, 'rotateVerifyToken'])->name('setup.verify-token');

    Route::get('oauth/url', [TikTokConnectionController::class, 'authorizationUrl'])->name('oauth.url');
    Route::post('oauth/callback', [TikTokConnectionController::class, 'callback'])->name('oauth.callback');

    Route::get('token', [TikTokConnectionController::class, 'tokenStatus'])->name('token.status');
    Route::post('token/refresh', [TikTokConnectionController::class, 'refreshToken'])->name('token.refresh');

    Route::get('advertisers', [TikTokConnectionController::class, 'advertisers'])->name('advertisers.index');
    Route::post('advertisers/sync', [TikTokConnectionController::class, 'syncAdvertisers'])->name('advertisers.sync');
    Route::get('reports/campaigns', [TikTokConnectionController::class, 'campaignReport'])->name('reports.campaigns');

    Route::get('lead-forms/available', [TikTokLeadFormController::class, 'forms'])->name('lead-forms.available');
    Route::get('lead-forms', [TikTokLeadFormController::class, 'index'])->name('lead-forms.index');
    Route::post('lead-forms', [TikTokLeadFormController::class, 'store'])->name('lead-forms.store');
    Route::put('lead-forms/{mapping}', [TikTokLeadFormController::class, 'update'])->name('lead-forms.update');
    Route::delete('lead-forms/{mapping}', [TikTokLeadFormController::class, 'destroy'])->name('lead-forms.destroy');
    Route::post('lead-forms/{mapping}/sync', [TikTokLeadFormController::class, 'sync'])->name('lead-forms.sync');

    Route::get('audiences', [TikTokAudienceController::class, 'index'])->name('audiences.index');
    Route::post('audiences', [TikTokAudienceController::class, 'store'])->name('audiences.store');
    Route::put('audiences/{sync}', [TikTokAudienceController::class, 'update'])->name('audiences.update');
    Route::delete('audiences/{sync}', [TikTokAudienceController::class, 'destroy'])->name('audiences.destroy');
    Route::post('audiences/{sync}/sync', [TikTokAudienceController::class, 'sync'])->name('audiences.sync');

    Route::get('event-mappings', [TikTokEventMappingController::class, 'index'])->name('event-mappings.index');
    Route::post('event-mappings', [TikTokEventMappingController::class, 'store'])->name('event-mappings.store');
    Route::put('event-mappings/{mapping}', [TikTokEventMappingController::class, 'update'])->name('event-mappings.update');
    Route::delete('event-mappings/{mapping}', [TikTokEventMappingController::class, 'destroy'])->name('event-mappings.destroy');

    Route::get('event-logs', [TikTokEventMappingController::class, 'logs'])->name('event-logs.index');
    Route::post('event-logs/{log}/retry', [TikTokEventMappingController::class, 'retryLog'])->name('event-logs.retry');
});
