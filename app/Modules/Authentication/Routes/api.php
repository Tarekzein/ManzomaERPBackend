<?php

use App\Modules\Authentication\Http\Controllers\AuthController;
use App\Modules\Authentication\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

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
    Route::get('/users', [UserManagementController::class, 'index'])->name('users.index');
    Route::post('/users', [UserManagementController::class, 'store'])->name('users.store');
    Route::get('/roles', [UserManagementController::class, 'roles'])->name('roles.index');
    Route::patch('/users/{user}/role', [UserManagementController::class, 'updateRole'])->name('users.role.update');
});
