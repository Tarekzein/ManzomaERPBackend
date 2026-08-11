<?php

namespace App\Modules\TikTokIntegration\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\TikTokIntegration\Http\Requests\TikTokRequest;
use App\Modules\TikTokIntegration\Models\TikTokAdvertiser;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use App\Modules\TikTokIntegration\Policies\TikTokIntegrationPolicy;
use App\Modules\TikTokIntegration\Services\TikTokAdvertiserService;
use App\Modules\TikTokIntegration\Services\TikTokOAuthService;
use App\Modules\TikTokIntegration\Services\TikTokSetupService;
use App\Modules\TikTokIntegration\Services\TikTokTokenService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TikTokConnectionController extends Controller
{
    public function __construct(
        private readonly TikTokOAuthService $oauth,
        private readonly TikTokAdvertiserService $advertisers,
        private readonly TikTokIntegrationPolicy $policy,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success(
            TikTokConnection::with('advertisers')->where('company_id', $companyId)->first(),
            'TikTok connection loaded'
        );
    }

    public function storeAppCredentials(TikTokRequest $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'tiktok.create');

        return ApiResponse::success(
            $this->oauth->saveAppCredentials(
                $companyId,
                $request->user(),
                $request->string('app_id'),
                $request->string('app_secret'),
            ),
            'TikTok app credentials saved'
        );
    }

    public function authorizationUrl(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success($this->oauth->authorizationUrl($companyId, $request->user()));
    }

    public function callback(TikTokRequest $request): JsonResponse
    {
        return ApiResponse::success(
            $this->oauth->handleCallback($request->string('auth_code'), $request->string('state')),
            'TikTok account connected'
        );
    }

    public function destroy(Request $request): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'tiktok.delete');
        $purge = $request->boolean('purge');
        $this->oauth->disconnect($companyId, $purge);

        return ApiResponse::success(null, $purge
            ? 'TikTok account disconnected and history erased'
            : 'TikTok account disconnected');
    }

    public function saveSettings(TikTokRequest $request): JsonResponse
    {
        $connection = $this->connection($request, 'tiktok.edit');
        $connection->update($request->validated());

        return ApiResponse::success($connection->fresh(), 'TikTok settings updated');
    }

    public function advertisers(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        return ApiResponse::success(
            TikTokAdvertiser::where('tiktok_connection_id', $connection->id)->orderBy('name')->get(),
            'Advertiser accounts loaded'
        );
    }

    public function syncAdvertisers(Request $request): JsonResponse
    {
        $connection = $this->connection($request, 'tiktok.edit');

        return ApiResponse::success($this->advertisers->sync($connection), 'Advertiser accounts synchronised');
    }

    public function campaignReport(TikTokRequest $request): JsonResponse
    {
        $connection = $this->connection($request);

        return ApiResponse::success(
            $this->advertisers->campaignReport(
                $connection,
                $request->string('advertiser_id'),
                $request->string('start_date'),
                $request->string('end_date'),
            ),
            'Campaign report loaded'
        );
    }

    /** Everything the company needs to configure their own TikTok app. */
    public function setup(Request $request, TikTokSetupService $setup): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user());

        return ApiResponse::success($setup->instructions($companyId), 'TikTok setup details loaded');
    }

    public function rotateVerifyToken(Request $request, TikTokSetupService $setup): JsonResponse
    {
        $companyId = $this->policy->companyId($request->user(), 'tiktok.edit');
        $connection = TikTokConnection::where('company_id', $companyId)->firstOrFail();

        return ApiResponse::success(
            ['verify_token' => $setup->rotateVerifyToken($connection)],
            'A new verify token was issued. Update it in your TikTok app settings.'
        );
    }

    public function tokenStatus(Request $request): JsonResponse
    {
        $connection = $this->connection($request);

        return ApiResponse::success([
            'status' => $connection->status,
            'expires_at' => $connection->access_token_expires_at?->toISOString(),
            'expires_in_days' => $connection->access_token_expires_at
                ? max(0, (int) ceil(now()->diffInHours($connection->access_token_expires_at, false) / 24))
                : null,
            'can_refresh' => $connection->canRefresh(),
            'refresh_token_expires_at' => $connection->refresh_token_expires_at?->toISOString(),
            'granted_scopes' => $connection->granted_scopes ?? [],
            'scopes_checked_at' => $connection->scopes_checked_at?->toISOString(),
        ], 'Token status loaded');
    }

    public function refreshToken(Request $request, TikTokTokenService $tokens): JsonResponse
    {
        $connection = $this->connection($request, 'tiktok.edit');
        $outcome = $tokens->maintain($connection);

        return ApiResponse::success(
            ['outcome' => $outcome, 'connection' => $connection->fresh()],
            match ($outcome) {
                'refreshed' => 'Access token refreshed',
                'expired' => 'The token is no longer valid: reconnect the account',
                'expiring' => 'The token could not be renewed automatically: reconnect the account',
                default => 'Connection is healthy',
            }
        );
    }

    private function connection(Request $request, string $permission = 'tiktok.view'): TikTokConnection
    {
        $companyId = $this->policy->companyId($request->user(), $permission);

        return TikTokConnection::where('company_id', $companyId)->firstOrFail();
    }
}
