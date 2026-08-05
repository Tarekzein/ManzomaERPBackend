<?php

namespace App\Modules\Subscriptions\Providers;

use App\Modules\Subscriptions\Console\ProcessSubscriptionRenewals;
use App\Modules\Subscriptions\Console\SendSubscriptionReminders;
use App\Modules\Subscriptions\Contracts\CompanySubscriptionRepository;
use App\Modules\Subscriptions\Contracts\FeatureRepository;
use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\Repositories\EloquentCompanySubscriptionRepository;
use App\Modules\Subscriptions\Repositories\EloquentFeatureRepository;
use App\Modules\Subscriptions\Repositories\EloquentPlanRepository;
use App\Modules\Subscriptions\Services\MockPaymobGateway;
use App\Modules\Subscriptions\Services\PaymobCheckoutGateway;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class SubscriptionsServiceProvider extends ServiceProvider
{
    public array $bindings = [
        PlanRepository::class => EloquentPlanRepository::class,
        FeatureRepository::class => EloquentFeatureRepository::class,
        CompanySubscriptionRepository::class => EloquentCompanySubscriptionRepository::class,
    ];

    public function register(): void
    {
        // PAYMOB_MODE=mock keeps the local fake checkout; any other mode talks
        // to Paymob with the configured credentials.
        $this->app->bind(PaymobGateway::class, fn ($app) => config('services.paymob.mode') === 'mock'
            ? $app->make(MockPaymobGateway::class)
            : $app->make(PaymobCheckoutGateway::class));
    }

    public function boot(): void
    {
        Route::middleware('api')->prefix('api')->group(__DIR__.'/../Routes/api.php');

        if ($this->app->runningInConsole()) {
            $this->commands([ProcessSubscriptionRenewals::class, SendSubscriptionReminders::class]);
        }
    }
}
