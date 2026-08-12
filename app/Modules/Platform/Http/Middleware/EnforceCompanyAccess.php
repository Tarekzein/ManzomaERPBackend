<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class EnforceCompanyAccess
{
    public function __construct(
        private readonly EffectiveAccessService $access,
        private readonly CompanyContext $context,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Signing in is never blocked by suspension. A stale token from a
        // suspended tenant must not stop someone re-authenticating — as
        // another account, or to pick the suspension notice back up.
        if ($request->is('api/auth/login', 'api/auth/register')) {
            return $next($request);
        }

        $user = $request->user();
        $company = $this->context->company();
        $organization = $this->context->organization();

        // A deactivated account keeps its session so it can be told why, but
        // reaches nothing beyond the session endpoints. Only an explicit false
        // counts: the column defaults to true and is often simply unset.
        if ($user && ! $user->isSuperAdmin() && $user->is_active === false && ! $this->isSessionRequest($request)) {
            return $this->codedError(
                'ACCOUNT_SUSPENDED',
                'Your account has been deactivated.',
            );
        }

        if ($user && ! $user->isSuperAdmin() && $organization?->status === 'suspended' && ! $this->isOrganizationRecoveryRequest($request)) {
            return $this->codedError(
                'ORGANIZATION_SUSPENDED',
                'Your organization account is suspended.',
            );
        }

        if ($user && ! $user->isSuperAdmin() && $organization?->billing_suspended_at && ! $this->isBillingRequest($request)) {
            return $this->codedError(
                'ORGANIZATION_BILLING_SUSPENDED',
                'Your organization subscription is suspended.',
            );
        }

        if ($user && ! $user->isSuperAdmin() && $company && $company->is_active !== true) {
            // Company administration is independent from organization
            // billing. A suspended workspace stays closed for ERP data while
            // organization, billing and session recovery routes remain usable.
            if (! $this->isBillingRequest($request) && ! $this->isOrganizationRecoveryRequest($request)) {
                return ApiResponse::error('Your company account is suspended.', status: 403);
            }
        }

        if ($user?->must_change_password && ! $request->is('api/auth/change-password', 'api/auth/logout*', 'api/auth/me')) {
            return ApiResponse::error('You must change your password before continuing.', status: 403);
        }

        if ($user && ! $user->isSuperAdmin() && $user->last_activity_at) {
            $hours = max((int) data_get($company?->settings, 'session_timeout_hours', 8), 1);

            if (Carbon::parse($user->last_activity_at)->lt(now()->subHours($hours))) {
                $user->tokens()->delete();

                return ApiResponse::error('Your session expired due to inactivity.', status: 401);
            }
        }

        if ($user && ! $user->isSuperAdmin() && $company) {
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

    private function codedError(string $code, string $message): Response
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'data' => null,
            'message' => $message,
            'errors' => (object) [],
            'meta' => (object) [],
        ], 403);
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
            'api/organizations',
            'api/organizations/*',
            'api/workspace/*',
            'api/auth/me',
            'api/auth/logout*',
        );
    }

    /** What a blocked session may still reach: itself, and the way out. */
    private function isSessionRequest(Request $request): bool
    {
        return $request->is('api/auth/me', 'api/auth/logout*');
    }

    private function isOrganizationRecoveryRequest(Request $request): bool
    {
        return $request->is(
            'api/organizations',
            'api/organizations/*',
            'api/workspace/*',
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
