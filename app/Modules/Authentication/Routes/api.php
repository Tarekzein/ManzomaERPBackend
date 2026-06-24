<?php

use App\Modules\Authentication\Http\Controllers\AuthController;
use App\Modules\Authentication\Http\Controllers\CustomRoleController;
use App\Modules\Authentication\Http\Controllers\TrustedDeviceController;
use App\Modules\Authentication\Http\Controllers\TwoFactorController;
use App\Modules\Authentication\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->name('register');
    Route::post('/login', [AuthController::class, 'login'])->name('login');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])->name('me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->name('logout-all');
        Route::post('/change-password', [AuthController::class, 'changePassword'])->name('change-password');
        Route::get('/two-factor', [TwoFactorController::class, 'status'])->name('two-factor.status');
        Route::post('/two-factor/enable', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
        Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
        Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
        Route::get('/trusted-devices', [TrustedDeviceController::class, 'index'])->name('trusted-devices.index');
        Route::delete('/trusted-devices', [TrustedDeviceController::class, 'destroyAll'])->name('trusted-devices.destroy-all');
        Route::delete('/trusted-devices/{device}', [TrustedDeviceController::class, 'destroy'])->name('trusted-devices.destroy');
    });
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('/roles', [UserManagementController::class, 'roles'])->name('roles.index');
    Route::get('/permissions', [UserManagementController::class, 'permissions'])->name('permissions.index');
    Route::patch('/users/{user}', [UserManagementController::class, 'update'])->name('users.update');
    Route::patch('/users/{user}/role', [UserManagementController::class, 'updateRole'])->name('users.role.update');
    Route::post('/users/{user}/activate', [UserManagementController::class, 'activate'])->name('users.activate');
    Route::post('/users/{user}/deactivate', [UserManagementController::class, 'deactivate'])->name('users.deactivate');
    Route::post('/users/{user}/force-password-reset', [UserManagementController::class, 'forcePasswordReset'])->name('users.force-password-reset');
    Route::delete('/users/{user}', [UserManagementController::class, 'destroy'])->name('users.destroy');
    Route::get('/custom-roles', [CustomRoleController::class, 'index'])->name('custom-roles.index');
    Route::post('/custom-roles', [CustomRoleController::class, 'store'])->name('custom-roles.store');
    Route::put('/custom-roles/{role}', [CustomRoleController::class, 'update'])->name('custom-roles.update');
    Route::delete('/custom-roles/{role}', [CustomRoleController::class, 'destroy'])->name('custom-roles.destroy');
    Route::post('/users/{user}/custom-role', [CustomRoleController::class, 'assign'])->name('users.custom-role');
});
