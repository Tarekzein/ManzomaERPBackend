<?php

namespace App\Modules\Subscriptions\Providers;

use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Contracts\FeatureRepository;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\Repositories\EloquentCompanySubscriptionRepository;
use App\Modules\Subscriptions\Repositories\EloquentFeatureRepository;
use App\Modules\Subscriptions\Repositories\EloquentPlanRepository;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SubscriptionsServiceProvider extends ServiceProvider
{
    public array $bindings = [
        PlanRepository::class => EloquentPlanRepository::class,
        FeatureRepository::class => EloquentFeatureRepository::class,
        CompanySubscriptionRepository::class => EloquentCompanySubscriptionRepository::class,
    ];

    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');
    }
}
