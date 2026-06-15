<?php

namespace App\Modules\Reporting\Providers;

use App\Modules\Reporting\Console\RunScheduledReports;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ReportingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'reporting');
        if ($this->app->runningInConsole()) {
            $this->commands([RunScheduledReports::class]);
        }
    }
}
