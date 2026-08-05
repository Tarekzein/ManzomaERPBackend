<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Services\EffectiveAccessService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class EnforceCompanyAccess
{
    public function __construct(private readonly EffectiveAccessService $access) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isSuperAdmin() && $user->company?->is_active !== true) {
            // A company suspended for an unpaid subscription keeps access to
            // the billing endpoints so its admins can settle and come back.
            if (! ($user->company?->isBillingSuspended() && $this->isBillingRequest($request))) {
                return ApiResponse::error('Your company account is suspended.', status: 403);
            }

            return $next($request);
        }

        if ($user?->must_change_password && ! $request->is('api/auth/change-password', 'api/auth/logout*', 'api/auth/me')) {
            return ApiResponse::error('You must change your password before continuing.', status: 403);
        }

        if ($user && ! $user->isSuperAdmin() && $user->last_activity_at) {
            $hours = max((int) data_get($user->company?->settings, 'session_timeout_hours', 8), 1);

            if (Carbon::parse($user->last_activity_at)->lt(now()->subHours($hours))) {
                $user->tokens()->delete();

                return ApiResponse::error('Your session expired due to inactivity.', status: 401);
            }
        }

        if ($user && ! $user->isSuperAdmin() && $user->company?->subscription) {
            $feature = $this->access->featureForPath($request->path());

            if ($feature && ! $this->access->hasFeature($user, $feature)) {
                return ApiResponse::error('Your subscription does not include this feature.', status: 403);
            }
        }

        if ($user && ! $user->isSuperAdmin() && ($module = $this->access->moduleForPath($request->path()))) {
            $permission = $this->permissionForRequest($request, $module);

            if (! $this->access->can($user, $permission, $module)) {
                return ApiResponse::error('You do not have permission to perform this action.', status: 403);
            }
        }

        return $next($request);
    }

    private function isBillingRequest(Request $request): bool
    {
        return $request->is(
            'api/subscriptions/current',
            'api/subscriptions/plans',
            'api/subscriptions/features',
            'api/subscriptions/checkout',
            'api/subscriptions/renew',
            'api/subscriptions/subscribe',
            'api/subscriptions/payments',
            'api/subscriptions/payments/*',
            'api/payments/*',
            'api/auth/me',
            'api/auth/logout*',
        );
    }

    private function permissionForRequest(Request $request, string $module): string
    {
        if ($request->is('api/hr/me') || ($request->is('api/hr/leave-requests') && $request->isMethod('post'))) {
            return 'hr.view';
        }

        return $this->access->permissionForAction($module, $this->actionForRequest($request));
    }

    private function actionForRequest(Request $request): string
    {
        if ($request->isMethod('get')) {
            return preg_match('/(export|pdf|download)/i', $request->path()) ? 'export' : 'view';
        }

        return match (strtolower($request->method())) {
            'delete' => 'delete',
            'put', 'patch' => 'edit',
            default => preg_match('/(post|confirm|approve|reject|review|complete|close|ship|invoice|receive|reconcile|lock|sync|move|reorder|process|email)/i', $request->path())
                ? 'edit'
                : 'create',
        };
    }
}
