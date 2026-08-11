<?php

use App\Modules\MetaIntegration\Http\Controllers\MetaAudienceController;
use App\Modules\MetaIntegration\Http\Controllers\MetaConnectionController;
use App\Modules\MetaIntegration\Http\Controllers\MetaEventMappingController;
use App\Modules\MetaIntegration\Http\Controllers\MetaLeadFormController;
use App\Modules\MetaIntegration\Http\Controllers\MetaPageController;
use App\Modules\MetaIntegration\Http\Controllers\MetaWhatsAppController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('meta')->name('meta.')->group(function () {
    Route::get('connection', [MetaConnectionController::class, 'show'])->name('connection.show');
    Route::delete('connection', [MetaConnectionController::class, 'destroy'])->name('connection.destroy');
    Route::post('connection/manual', [MetaConnectionController::class, 'storeManual'])->name('connection.manual');
    Route::post('connection/app-credentials', [MetaConnectionController::class, 'storeAppCredentials'])->name('connection.app-credentials');
    Route::post('connection/assets', [MetaConnectionController::class, 'saveAssets'])->name('connection.assets');
    Route::put('connection/compliance', [MetaConnectionController::class, 'updateCompliance'])->name('connection.compliance');
    Route::get('oauth/url', [MetaConnectionController::class, 'authorizationUrl'])->name('oauth.url');
    Route::post('oauth/callback', [MetaConnectionController::class, 'callback'])->name('oauth.callback');
    Route::post('test-event', [MetaConnectionController::class, 'sendTestEvent'])->name('test-event');
    Route::get('health', [MetaConnectionController::class, 'health'])->name('health');

    // Per-company Meta App setup: the values each tenant pastes into their app.
    Route::get('setup', [MetaConnectionController::class, 'setup'])->name('setup');
    Route::post('setup/verify-token', [MetaConnectionController::class, 'rotateVerifyToken'])->name('setup.verify-token');

    Route::get('assets/businesses', [MetaConnectionController::class, 'businesses'])->name('assets.businesses');
    Route::get('assets/ad-accounts', [MetaConnectionController::class, 'adAccounts'])->name('assets.ad-accounts');
    Route::get('assets/pixels', [MetaConnectionController::class, 'pixels'])->name('assets.pixels');
    Route::get('assets/pages', [MetaConnectionController::class, 'pages'])->name('assets.pages');
    Route::get('assets/lead-forms', [MetaConnectionController::class, 'leadForms'])->name('assets.lead-forms');

    // Connected Pages, their Instagram accounts, and webhook subscriptions.
    Route::get('pages', [MetaPageController::class, 'index'])->name('pages.index');
    Route::post('pages/sync', [MetaPageController::class, 'sync'])->name('pages.sync');
    Route::post('pages/{page}/subscribe', [MetaPageController::class, 'subscribe'])->name('pages.subscribe');
    Route::delete('pages/{page}/subscribe', [MetaPageController::class, 'unsubscribe'])->name('pages.unsubscribe');
    Route::get('pages/{page}/subscription', [MetaPageController::class, 'verify'])->name('pages.subscription');
    Route::get('instagram/accounts', [MetaPageController::class, 'instagramAccounts'])->name('instagram.accounts');
    Route::get('instagram/{instagramAccountId}/profile', [MetaPageController::class, 'instagramProfile'])->name('instagram.profile');

    // Token lifecycle.
    Route::get('token', [MetaPageController::class, 'tokenStatus'])->name('token.status');
    Route::post('token/refresh', [MetaPageController::class, 'refreshToken'])->name('token.refresh');

    Route::get('event-mappings', [MetaEventMappingController::class, 'index'])->name('event-mappings.index');
    Route::post('event-mappings', [MetaEventMappingController::class, 'store'])->name('event-mappings.store');
    Route::put('event-mappings/{mapping}', [MetaEventMappingController::class, 'update'])->name('event-mappings.update');
    Route::delete('event-mappings/{mapping}', [MetaEventMappingController::class, 'destroy'])->name('event-mappings.destroy');

    Route::get('event-logs', [MetaEventMappingController::class, 'logs'])->name('event-logs.index');
    Route::post('event-logs/{log}/retry', [MetaEventMappingController::class, 'retryLog'])->name('event-logs.retry');

    Route::get('lead-forms', [MetaLeadFormController::class, 'index'])->name('lead-form-mappings.index');
    Route::post('lead-forms', [MetaLeadFormController::class, 'store'])->name('lead-form-mappings.store');
    Route::put('lead-forms/{mapping}', [MetaLeadFormController::class, 'update'])->name('lead-form-mappings.update');
    Route::delete('lead-forms/{mapping}', [MetaLeadFormController::class, 'destroy'])->name('lead-form-mappings.destroy');
    Route::post('lead-forms/{mapping}/backfill', [MetaLeadFormController::class, 'backfill'])->name('lead-form-mappings.backfill');

    Route::get('whatsapp/business-accounts', [MetaWhatsAppController::class, 'businessAccounts'])->name('whatsapp.business-accounts');
    Route::get('whatsapp/phone-numbers', [MetaWhatsAppController::class, 'phoneNumbers'])->name('whatsapp.phone-numbers');
    Route::put('whatsapp/settings', [MetaWhatsAppController::class, 'saveSettings'])->name('whatsapp.settings');
    Route::post('whatsapp/send', [MetaWhatsAppController::class, 'sendTemplate'])->name('whatsapp.send');

    Route::get('audiences', [MetaAudienceController::class, 'index'])->name('audiences.index');
    Route::post('audiences', [MetaAudienceController::class, 'store'])->name('audiences.store');
    Route::put('audiences/{sync}', [MetaAudienceController::class, 'update'])->name('audiences.update');
    Route::delete('audiences/{sync}', [MetaAudienceController::class, 'destroy'])->name('audiences.destroy');
    Route::post('audiences/{sync}/sync', [MetaAudienceController::class, 'sync'])->name('audiences.sync');
    Route::get('audiences/{sync}/status', [MetaAudienceController::class, 'status'])->name('audiences.status');
});
