<?php

namespace App\Modules\Inventory\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
