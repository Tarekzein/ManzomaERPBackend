<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Companies\Providers\CompaniesServiceProvider;
use App\Modules\CRM\Providers\CRMServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\HR\Providers\HRServiceProvider;
use App\Modules\Inventory\Providers\InventoryServiceProvider;
use App\Modules\Notifications\Providers\NotificationsServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Modules\Reporting\Providers\ReportingServiceProvider;
use App\Modules\Sales\Providers\SalesServiceProvider;
use App\Modules\Subscriptions\Providers\SubscriptionsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    CompaniesServiceProvider::class,
    CRMServiceProvider::class,
    FinanceServiceProvider::class,
    InventoryServiceProvider::class,
    NotificationsServiceProvider::class,
    HRServiceProvider::class,
    SubscriptionsServiceProvider::class,
    AuthenticationServiceProvider::class,
    ProjectsServiceProvider::class,
    ReportingServiceProvider::class,
    SalesServiceProvider::class,
    PlatformServiceProvider::class,
    HorizonServiceProvider::class,
];
