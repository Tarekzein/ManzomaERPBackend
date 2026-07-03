<?php

use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\ProjectTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects/gantt', [ProjectController::class, 'gantt'])->name('projects.gantt');
    Route::apiResource('projects', ProjectController::class);
    Route::get('/projects/{project}/report', [ProjectController::class, 'report'])->name('projects.report');

    // Project sub-resources: create
    Route::post('/projects/{project}/expenses', [ProjectController::class, 'expense'])->name('projects.expenses.store');
    Route::post('/projects/{project}/attachments', [ProjectController::class, 'attach'])->name('projects.attachments.store');
    Route::post('/projects/{project}/comments', [ProjectController::class, 'comment'])->name('projects.comments.store');

    // Project sub-resources: delete
    Route::delete('/projects/{project}/expenses/{expense}', [ProjectController::class, 'destroyExpense'])->name('projects.expenses.destroy');
    Route::delete('/projects/{project}/attachments/{attachment}', [ProjectController::class, 'destroyAttachment'])->name('projects.attachments.destroy');
    Route::delete('/projects/{project}/comments/{comment}', [ProjectController::class, 'destroyComment'])->name('projects.comments.destroy');

    // Task reorder (must come before tasks.index to avoid route ambiguity)
    Route::patch('/projects/{project}/tasks/reorder', [ProjectTaskController::class, 'reorder'])->name('projects.tasks.reorder');

    // Task CRUD
    Route::get('/projects/{project}/tasks', [ProjectTaskController::class, 'index'])->name('projects.tasks.index');
    Route::post('/projects/{project}/tasks', [ProjectTaskController::class, 'store'])->name('projects.tasks.store');
    Route::get('/project-tasks/{task}', [ProjectTaskController::class, 'show'])->name('project-tasks.show');
    Route::patch('/project-tasks/{task}', [ProjectTaskController::class, 'update'])->name('project-tasks.update');
    Route::delete('/project-tasks/{task}', [ProjectTaskController::class, 'destroy'])->name('project-tasks.destroy');

    // Task sub-resources: create
    Route::post('/project-tasks/{task}/time-logs', [ProjectTaskController::class, 'logTime'])->name('project-tasks.time-logs.store');
    Route::post('/project-tasks/{task}/attachments', [ProjectTaskController::class, 'attach'])->name('project-tasks.attachments.store');
    Route::post('/project-tasks/{task}/comments', [ProjectTaskController::class, 'comment'])->name('project-tasks.comments.store');

    // Task sub-resources: list + delete
    Route::get('/project-tasks/{task}/time-logs', [ProjectTaskController::class, 'timeLogs'])->name('project-tasks.time-logs.index');
    Route::delete('/project-tasks/{task}/time-logs/{timeLog}', [ProjectTaskController::class, 'destroyTimeLog'])->name('project-tasks.time-logs.destroy');
    Route::delete('/project-tasks/{task}/attachments/{attachment}', [ProjectTaskController::class, 'destroyAttachment'])->name('project-tasks.attachments.destroy');
    Route::delete('/project-tasks/{task}/comments/{comment}', [ProjectTaskController::class, 'destroyComment'])->name('project-tasks.comments.destroy');
});
