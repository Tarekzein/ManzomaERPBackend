<?php

namespace App\Modules\Organizations\Providers;

use App\Modules\Organizations\Console\BackfillOrganizationStructure;
use App\Modules\Organizations\Console\ReconcileOrganizationQuotas;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class OrganizationsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                BackfillOrganizationStructure::class,
                ReconcileOrganizationQuotas::class,
            ]);
        }

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
