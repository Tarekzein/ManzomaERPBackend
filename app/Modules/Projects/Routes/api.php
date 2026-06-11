<?php

use App\Modules\Projects\Http\Controllers\ProjectController;
use App\Modules\Projects\Http\Controllers\ProjectTaskController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/projects/gantt', [ProjectController::class, 'gantt'])->name('projects.gantt');
    Route::apiResource('projects', ProjectController::class);
    Route::get('/projects/{project}/report', [ProjectController::class, 'report'])->name('projects.report');
    Route::post('/projects/{project}/expenses', [ProjectController::class, 'expense'])->name('projects.expenses.store');
    Route::post('/projects/{project}/attachments', [ProjectController::class, 'attach'])->name('projects.attachments.store');
    Route::post('/projects/{project}/comments', [ProjectController::class, 'comment'])->name('projects.comments.store');

    Route::get('/projects/{project}/tasks', [ProjectTaskController::class, 'index'])->name('projects.tasks.index');
    Route::post('/projects/{project}/tasks', [ProjectTaskController::class, 'store'])->name('projects.tasks.store');
    Route::get('/project-tasks/{task}', [ProjectTaskController::class, 'show'])->name('project-tasks.show');
    Route::patch('/project-tasks/{task}', [ProjectTaskController::class, 'update'])->name('project-tasks.update');
    Route::delete('/project-tasks/{task}', [ProjectTaskController::class, 'destroy'])->name('project-tasks.destroy');
    Route::post('/project-tasks/{task}/time-logs', [ProjectTaskController::class, 'logTime'])->name('project-tasks.time-logs.store');
    Route::post('/project-tasks/{task}/attachments', [ProjectTaskController::class, 'attach'])->name('project-tasks.attachments.store');
    Route::post('/project-tasks/{task}/comments', [ProjectTaskController::class, 'comment'])->name('project-tasks.comments.store');
});
