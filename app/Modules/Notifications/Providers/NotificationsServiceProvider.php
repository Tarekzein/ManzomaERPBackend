<?php

namespace App\Modules\Notifications\Providers;

use App\Modules\Notifications\Console\SendDueNotifications;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class NotificationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
        if ($this->app->runningInConsole()) {
            $this->commands([SendDueNotifications::class]);
        }
    }
}
