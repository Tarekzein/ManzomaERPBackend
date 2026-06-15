<?php

use App\Modules\CRM\Http\Controllers\CRMController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->prefix('crm')->name('crm.')->group(function () {
    Route::get('contacts', [CRMController::class, 'contacts'])->name('contacts.index');
    Route::post('contacts', [CRMController::class, 'storeContact'])->name('contacts.store');
    Route::put('contacts/{contact}', [CRMController::class, 'updateContact'])->name('contacts.update');
    Route::delete('contacts/{contact}', [CRMController::class, 'deleteContact'])->name('contacts.destroy');
    Route::post('contacts/{contact}/convert', [CRMController::class, 'convertContact'])->name('contacts.convert');

    Route::get('tags', [CRMController::class, 'tags'])->name('tags.index');
    Route::post('tags', [CRMController::class, 'storeTag'])->name('tags.store');
    Route::put('tags/{tag}', [CRMController::class, 'updateTag'])->name('tags.update');
    Route::delete('tags/{tag}', [CRMController::class, 'deleteTag'])->name('tags.destroy');

    Route::get('pipeline-stages', [CRMController::class, 'stages'])->name('stages.index');
    Route::post('pipeline-stages', [CRMController::class, 'storeStage'])->name('stages.store');
    Route::put('pipeline-stages/{stage}', [CRMController::class, 'updateStage'])->name('stages.update');
    Route::delete('pipeline-stages/{stage}', [CRMController::class, 'deleteStage'])->name('stages.destroy');
    Route::post('pipeline-stages/reorder', [CRMController::class, 'reorderStages'])->name('stages.reorder');

    Route::get('opportunities', [CRMController::class, 'opportunities'])->name('opportunities.index');
    Route::post('opportunities', [CRMController::class, 'storeOpportunity'])->name('opportunities.store');
    Route::put('opportunities/{opportunity}', [CRMController::class, 'updateOpportunity'])->name('opportunities.update');
    Route::delete('opportunities/{opportunity}', [CRMController::class, 'deleteOpportunity'])->name('opportunities.destroy');
    Route::post('opportunities/{opportunity}/move', [CRMController::class, 'moveOpportunity'])->name('opportunities.move');

    Route::get('activities', [CRMController::class, 'activities'])->name('activities.index');
    Route::post('activities', [CRMController::class, 'storeActivity'])->name('activities.store');

    Route::get('tasks', [CRMController::class, 'tasks'])->name('tasks.index');
    Route::post('tasks', [CRMController::class, 'storeTask'])->name('tasks.store');
    Route::put('tasks/{task}', [CRMController::class, 'updateTask'])->name('tasks.update');
    Route::delete('tasks/{task}', [CRMController::class, 'deleteTask'])->name('tasks.destroy');
    Route::post('tasks/{task}/complete', [CRMController::class, 'completeTask'])->name('tasks.complete');

    Route::get('segments', [CRMController::class, 'segments'])->name('segments.index');
    Route::post('segments', [CRMController::class, 'storeSegment'])->name('segments.store');
    Route::put('segments/{segment}', [CRMController::class, 'updateSegment'])->name('segments.update');
    Route::delete('segments/{segment}', [CRMController::class, 'deleteSegment'])->name('segments.destroy');
    Route::get('segments/{segment}/contacts', [CRMController::class, 'segmentContacts'])->name('segments.contacts');

    Route::get('campaigns', [CRMController::class, 'campaigns'])->name('campaigns.index');
    Route::post('campaigns', [CRMController::class, 'storeCampaign'])->name('campaigns.store');
    Route::put('campaigns/{campaign}', [CRMController::class, 'updateCampaign'])->name('campaigns.update');
    Route::delete('campaigns/{campaign}', [CRMController::class, 'deleteCampaign'])->name('campaigns.destroy');
    Route::post('campaign-webhooks/{provider}', [CRMController::class, 'campaignWebhook'])->name('campaign-webhooks.store');

    Route::get('reports/{report}', [CRMController::class, 'report'])->name('reports.show');
});
