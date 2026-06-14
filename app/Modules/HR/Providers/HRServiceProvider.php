<?php

namespace App\Modules\HR\Providers;

use App\Modules\HR\Contracts\HRRepository;
use App\Modules\HR\Repositories\EloquentHRRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class HRServiceProvider extends ServiceProvider
{
    public array $bindings = [HRRepository::class => EloquentHRRepository::class];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../../../config/hr.php', 'hr');
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
