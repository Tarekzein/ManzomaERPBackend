<?php

namespace App\Modules\Platform\Http\Middleware;

use App\Support\ApiResponse;
use App\Modules\Platform\Services\EffectiveAccessService;
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
            return ApiResponse::error('Your company account is suspended.', status: 403);
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
            $permission = $this->access->permissionForAction($module, $this->actionForRequest($request));

            if (! $this->access->can($user, $permission, $module)) {
                return ApiResponse::error('You do not have permission to perform this action.', status: 403);
            }
        }

        return $next($request);
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
