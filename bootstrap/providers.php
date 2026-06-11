<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Companies\Providers\CompaniesServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    CompaniesServiceProvider::class,
    FinanceServiceProvider::class,
    InventoryServiceProvider::class,
    SubscriptionsServiceProvider::class,
    AuthenticationServiceProvider::class,
    ProjectsServiceProvider::class,
    PlatformServiceProvider::class,
    HorizonServiceProvider::class,
];
