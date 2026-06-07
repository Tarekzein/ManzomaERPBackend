<?php

namespace App\Modules\Finance\Providers;

use App\Modules\Finance\Contracts\FinanceRepository;
use App\Modules\Finance\Repositories\EloquentFinanceRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class FinanceServiceProvider extends ServiceProvider
{
    public array $bindings = [FinanceRepository::class => EloquentFinanceRepository::class];

    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
