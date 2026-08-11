<?php

namespace App\Modules\MetaIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaPage;
use App\Modules\MetaIntegration\Policies\MetaIntegrationPolicy;
use App\Modules\MetaIntegration\Services\MetaPageService;
use App\Modules\MetaIntegration\Services\MetaTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Connected Facebook Pages, their Instagram Business accounts, and the webhook
 * subscriptions that make lead delivery work.
 */
class MetaPageController extends Controller
{
    public function __construct(
        private readonly MetaPageService $pages,
        private readonly MetaIntegrationPolicy $policy,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        return ApiResponse::success(
            MetaPage::where('meta_connection_id', $connection->id)->orderBy('name')->get(),
            'Connected pages loaded'
        );
    }

    public function sync(Request $request): JsonResponse
    {
        $connection = $this->connection($request, 'meta.edit');

        return ApiResponse::success($this->pages->sync($connection), 'Pages synchronised with Meta');
    }

    public function subscribe(Request $request, MetaPage $page): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $page);

        abort_unless($this->pages->subscribe($page), 502, $page->fresh()->last_error ?: 'Meta rejected the webhook subscription.');

        return ApiResponse::success($page->fresh(), 'Page subscribed to Meta webhooks');
    }

    public function unsubscribe(Request $request, MetaPage $page): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $page);
        $this->pages->unsubscribe($page);

        return ApiResponse::success($page->fresh(), 'Page unsubscribed from Meta webhooks');
    }

    /** Confirms Meta still has the subscription; it can drop silently. */
    public function verify(Request $request, MetaPage $page): JsonResponse
    {
        $this->policy->ensureOwned($request->user(), $page, 'meta.view');

        return ApiResponse::success([
            'page' => $page->fresh(),
            'subscribed' => $this->pages->verifySubscription($page),
        ], 'Subscription verified');
    }

    public function instagramAccounts(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->pages->instagramAccounts($this->connection($request)),
            'Instagram business accounts loaded'
        );
    }

    public function instagramProfile(Request $request, string $instagramAccountId): JsonResponse
    {
        return ApiResponse::success(
            $this->pages->instagramProfile($this->connection($request), $instagramAccountId),
            'Instagram profile loaded'
        );
    }

    /** Token status: expiry, type, and the scopes Meta actually granted. */
    public function tokenStatus(Request $request, MetaTokenService $tokens): JsonResponse
    {
        $connection = $this->connection($request);
        $inspection = $tokens->inspect($connection);

        return ApiResponse::success([
            'status' => $connection->status,
            'token_type' => $inspection['type'],
            'valid' => $inspection['valid'],
            'expires_at' => $inspection['expires_at']?->toISOString(),
            'expires_in_days' => $connection->access_token_expires_at
                ? max(0, (int) ceil(now()->diffInHours($connection->access_token_expires_at, false) / 24))
                : null,
            'granted_scopes' => $inspection['scopes'],
            'declined_scopes' => $connection->declined_scopes ?? [],
            'scopes_checked_at' => $connection->scopes_checked_at?->toISOString(),
        ], 'Token status loaded');
    }

    public function refreshToken(Request $request, MetaTokenService $tokens): JsonResponse
    {
        $connection = $this->connection($request, 'meta.edit');
        $outcome = $tokens->maintain($connection);

        return ApiResponse::success([
            'outcome' => $outcome,
            'connection' => $connection->fresh(),
        ], match ($outcome) {
            'refreshed' => 'Access token refreshed',
            'permanent' => 'This is a system user token and does not expire',
            'expired' => 'The token is no longer valid: reconnect the account',
            'expiring' => 'The token could not be renewed automatically: reconnect the account',
            default => 'Connection is healthy',
        });
    }

    private function connection(Request $request, string $permission = 'meta.view'): MetaConnection
    {
        $companyId = $this->policy->companyId($request->user(), $permission);

        return MetaConnection::where('company_id', $companyId)->firstOrFail();
    }
}
