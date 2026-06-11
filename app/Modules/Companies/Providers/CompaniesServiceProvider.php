<?php

namespace App\Modules\Companies\Providers;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\Repositories\EloquentCompanyRepository;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;

class CompaniesServiceProvider extends ServiceProvider
{
    public array $bindings = [
        CompanyRepository::class => EloquentCompanyRepository::class,
    ];

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
