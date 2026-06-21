<?php

namespace App\Providers;

use App\Modules\Platform\Services\AuditService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('erp-api', function (Request $request) {
            $user = $request->user();
            $rate = $user?->company?->subscription?->plan?->api_rate_limit_per_minute
                ?? config('erp.api.rate_limit_per_minute', 60);

            return Limit::perMinute($rate)->by($user?->id ? "user:{$user->id}" : "ip:{$request->ip()}");
        });

        foreach (['created', 'updated', 'deleted'] as $event) {
            Event::listen("eloquent.{$event}: *", function (string $name, array $models) use ($event) {
                $model = $models[0] ?? null;

                if ($model instanceof Model) {
                    app(AuditService::class)->recordModel($model, $event);
                }
            });
        }
    }
}
