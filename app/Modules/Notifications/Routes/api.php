<?php

use App\Modules\Notifications\Http\Controllers\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('notifications')->name('notifications.')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->name('index');
    Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
    Route::post('{notification}/read', [NotificationController::class, 'read'])->name('read');
    Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
    Route::delete('{notification}', [NotificationController::class, 'destroy'])->name('destroy');
    Route::get('preferences', [NotificationController::class, 'preferences'])->name('preferences.index');
    Route::put('preferences', [NotificationController::class, 'updatePreferences'])->name('preferences.update');
    Route::get('settings', [NotificationController::class, 'settings'])->name('settings.show');
    Route::put('settings', [NotificationController::class, 'updateSettings'])->name('settings.update');
    Route::post('announce', [NotificationController::class, 'announce'])->name('announce');
    Route::get('deliveries', [NotificationController::class, 'deliveries'])->name('deliveries');
});
