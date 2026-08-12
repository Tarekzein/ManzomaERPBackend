<?php

namespace App\Providers;

use App\Modules\Platform\Services\AuditService;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
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
            $company = $user ? app(CompanyContext::class)->companyFor($user) : null;
            $rate = $company?->organization
                ? data_get(app(OrganizationEntitlementService::class)->forOrganization($company->organization), 'api_rate_limit_per_minute')
                : $company?->subscription?->plan?->api_rate_limit_per_minute;
            $rate = max((int) ($rate ?? config('erp.api.rate_limit_per_minute', 60)), 1);

            return Limit::perMinute($rate)->by(
                $user?->id
                    ? "user:{$user->id}:company:".($company?->getKey() ?? 'none')
                    : "ip:{$request->ip()}"
            );
        });

        // Meta delivers webhooks in bursts and drops events that get a 429, so
        // this ceiling only exists to stop abuse of the public endpoint.
        RateLimiter::for('meta-webhooks', fn (Request $request) => Limit::perMinute(600)->by($request->ip()));

        RateLimiter::for('invitation-registration', function (Request $request) {
            $tokenHash = hash('sha256', (string) $request->route('token'));

            return [
                Limit::perMinute(5)->by("invitation-registration:token:{$tokenHash}"),
                Limit::perMinute(30)->by("invitation-registration:ip:{$request->ip()}"),
            ];
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
