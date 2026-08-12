<?php

use App\Modules\Organizations\Http\Controllers\OrganizationController;
use App\Modules\Organizations\Http\Controllers\OrganizationInvitationController;
use App\Modules\Organizations\Http\Controllers\OrganizationMemberController;
use App\Modules\Organizations\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/organization-invitations/{token}', [OrganizationInvitationController::class, 'show'])
    ->name('organization-invitations.show');
Route::post('/organization-invitations/{token}/register', [OrganizationInvitationController::class, 'register'])
    ->middleware('throttle:invitation-registration')
    ->name('organization-invitations.register');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/workspace/bootstrap', [WorkspaceController::class, 'bootstrap'])->name('workspace.bootstrap');
    Route::put('/workspace/default', [WorkspaceController::class, 'setDefault'])->name('workspace.default');

    Route::post('/organization-invitations/{token}/accept', [OrganizationInvitationController::class, 'accept'])
        ->name('organization-invitations.accept');

    Route::prefix('organizations')->name('organizations.')->group(function () {
        Route::get('/', [OrganizationController::class, 'index'])->name('index');
        Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
        Route::patch('/{organization}', [OrganizationController::class, 'update'])->name('update');
        Route::post('/{organization}/suspend', [OrganizationController::class, 'suspend'])->name('suspend');
        Route::post('/{organization}/reactivate', [OrganizationController::class, 'reactivate'])->name('reactivate');

        Route::post('/{organization}/companies', [OrganizationController::class, 'storeCompany'])->name('companies.store');
        Route::post('/{organization}/companies/{company}/archive', [OrganizationController::class, 'archiveCompany'])
            ->name('companies.archive');
        Route::post('/{organization}/companies/{company}/restore', [OrganizationController::class, 'restoreCompany'])
            ->name('companies.restore');
        Route::post('/{organization}/companies/{company}/suspend', [OrganizationController::class, 'suspendCompany'])
            ->name('companies.suspend');
        Route::post('/{organization}/companies/{company}/reactivate', [OrganizationController::class, 'reactivateCompany'])
            ->name('companies.reactivate');

        Route::get('/{organization}/members', [OrganizationMemberController::class, 'index'])->name('members.index');
        Route::patch('/{organization}/members/{membership}', [OrganizationMemberController::class, 'update'])
            ->name('members.update');
        Route::put('/{organization}/members/{membership}/companies/{company}', [OrganizationMemberController::class, 'assignCompany'])
            ->name('members.companies.update');
        Route::delete('/{organization}/members/{membership}/companies/{company}', [OrganizationMemberController::class, 'removeCompany'])
            ->name('members.companies.destroy');

        Route::get('/{organization}/invitations', [OrganizationInvitationController::class, 'index'])->name('invitations.index');
        Route::post('/{organization}/invitations', [OrganizationInvitationController::class, 'store'])->name('invitations.store');
        Route::post('/{organization}/invitations/{invitation}/resend', [OrganizationInvitationController::class, 'resend'])
            ->name('invitations.resend');
        Route::delete('/{organization}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])
            ->name('invitations.destroy');
    });
});
