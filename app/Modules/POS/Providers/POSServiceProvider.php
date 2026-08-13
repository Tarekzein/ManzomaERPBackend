<?php

namespace App\Modules\POS\Providers;

use App\Modules\POS\Console\DispatchPosOutbox;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class POSServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([DispatchPosOutbox::class]);
        }
    }
}
