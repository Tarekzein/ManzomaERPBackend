<?php

namespace App\Modules\Projects\Providers;

use App\Modules\Projects\Contracts\ProjectActivityRepository;
use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Contracts\ProjectTaskRepository;
use App\Modules\Projects\Repositories\EloquentProjectActivityRepository;
use App\Modules\Projects\Repositories\EloquentProjectRepository;
use App\Modules\Projects\Repositories\EloquentProjectTaskRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ProjectsServiceProvider extends ServiceProvider
{
    public array $bindings = [
        ProjectRepository::class => EloquentProjectRepository::class,
        ProjectTaskRepository::class => EloquentProjectTaskRepository::class,
        ProjectActivityRepository::class => EloquentProjectActivityRepository::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/projects.php', 'projects');
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
