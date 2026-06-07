<?php

namespace App\Modules\Companies\Providers;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\Repositories\EloquentCompanyRepository;
use Illuminate\Support\ServiceProvider;

class CompaniesServiceProvider extends ServiceProvider
{
    public array $bindings = [
        CompanyRepository::class => EloquentCompanyRepository::class,
    ];
}
