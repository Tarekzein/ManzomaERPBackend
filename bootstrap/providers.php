<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Companies\Providers\CompaniesServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    CompaniesServiceProvider::class,
    SubscriptionsServiceProvider::class,
    AuthenticationServiceProvider::class,
    HorizonServiceProvider::class,
];
