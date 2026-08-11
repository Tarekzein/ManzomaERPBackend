<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaPage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class MetaOAuthService
{
    private const STATE_PREFIX = 'oauth.meta.';

    public function __construct(
        private readonly MetaPageService $pages,
        private readonly MetaTokenService $tokens,
    ) {}

    public function saveAppCredentials(int $companyId, User $user, string $appId, string $appSecret, ?string $configId = null): MetaConnection
    {
        $existing = MetaConnection::where('company_id', $companyId)->first();

        return MetaConnection::updateOrCreate(
            ['company_id' => $companyId],
            [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'oauth_config_id' => $configId,
                'connection_method' => 'oauth',
                'status' => 'pending',
                'connected_by' => $user->id,
                'webhook_verify_token' => $existing?->webhook_verify_token ?: Str::random(40),
            ],
        );
    }

    public function authorizationUrl(int $companyId, User $user): array
    {
        $connection = MetaConnection::where('company_id', $companyId)->first();

        // Every company connects their own Meta App; nothing is shared.
        if (! $connection?->hasAppCredentials()) {
            throw ValidationException::withMessages([
                'app_id' => ['Save your Meta App ID and App Secret before connecting.'],
            ]);
        }

        $appId = $connection->appId();

        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.$state, [
            'company_id' => $companyId,
            'user_id' => $user->id,
        ], now()->addMinutes(10));

        $version = config('meta.graph_version');

        $params = [
            'client_id' => $appId,
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'state' => $state,
        ];

        // Business-type Meta apps reject a classic scope list ("Invalid Scopes")
        // and require Facebook Login for Business with a Login Configuration ID.
        if ($configId = $connection?->oauth_config_id) {
            $params['config_id'] = $configId;
            $params['override_default_response_type'] = 'true';
        } else {
            $params['scope'] = implode(',', config('meta.scopes'));
        }

        return [
            'url' => rtrim(config('meta.oauth_dialog_url'), '/')."/{$version}/dialog/oauth?".http_build_query($params),
            'state' => $state,
            'expires_in' => 600,
        ];
    }

    public function handleCallback(string $code, string $state): MetaConnection
    {
        $payload = Cache::pull(self::STATE_PREFIX.$state);
        if (! $payload) {
            throw ValidationException::withMessages(['state' => ['The Meta connection session has expired.']]);
        }

        $connection = MetaConnection::where('company_id', $payload['company_id'])->first();

        if (! $connection?->hasAppCredentials()) {
            throw ValidationException::withMessages([
                'app_id' => ['Save your Meta App ID and App Secret before connecting.'],
            ]);
        }

        $appId = $connection->appId();
        $appSecret = $connection->appSecret();

        $shortLivedToken = $this->exchangeCode($code, $appId, $appSecret);
        [$longLivedToken, $expiresIn] = $this->exchangeForLongLivedToken($shortLivedToken, $appId, $appSecret);

        $connection = MetaConnection::updateOrCreate(
            ['company_id' => $payload['company_id']],
            [
                'connection_method' => 'oauth',
                'status' => 'connected',
                'access_token' => $longLivedToken,
                'access_token_expires_at' => $expiresIn ? now()->addSeconds($expiresIn) : null,
                'token_type' => $expiresIn ? 'user' : 'system_user',
                'token_refreshed_at' => now(),
                'token_expiry_notified_at' => null,
                'scopes' => config('meta.scopes'),
                'connected_by' => $payload['user_id'],
                'last_error' => null,
                'disconnected_at' => null,
                'last_health_check_at' => now(),
            ],
        );

        // Record what Meta actually granted and pull in the Pages/IG accounts
        // so the connection is usable straight away. Neither is fatal: a
        // connected account with a sync problem beats no account at all.
        foreach ([fn () => $this->tokens->syncPermissions($connection), fn () => $this->pages->sync($connection)] as $step) {
            try {
                $step();
            } catch (Throwable $exception) {
                Log::warning('[meta] post-connect step failed', [
                    'company_id' => $connection->company_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $connection->refresh();
    }

    public function storeManualCredentials(int $companyId, User $user, array $data): MetaConnection
    {
        return MetaConnection::updateOrCreate(
            ['company_id' => $companyId],
            array_filter([
                'connection_method' => 'manual',
                'status' => 'connected',
                'access_token' => $data['access_token'],
                'app_id' => $data['app_id'] ?? null,
                'app_secret' => $data['app_secret'] ?? null,
                'business_id' => $data['business_id'] ?? null,
                'ad_account_id' => $data['ad_account_id'] ?? null,
                'pixel_id' => $data['pixel_id'] ?? null,
                'connected_by' => $user->id,
                'last_error' => null,
                'last_health_check_at' => now(),
            ], fn ($value) => $value !== null),
        );
    }

    /**
     * Disconnecting revokes the app's access at Meta — otherwise the token
     * stays live there — and unsubscribes the Pages so Meta stops delivering.
     *
     * The connection row is kept and marked `disconnected` so event logs and
     * audience history survive; `purge: true` restores the old destructive
     * behaviour for a customer who wants everything gone.
     */
    public function disconnect(int $companyId, bool $purge = false): void
    {
        $connection = MetaConnection::where('company_id', $companyId)->first();

        if (! $connection) {
            return;
        }

        foreach (MetaPage::where('meta_connection_id', $connection->id)->get() as $page) {
            try {
                $this->pages->unsubscribe($page);
            } catch (Throwable $exception) {
                Log::warning('[meta] could not unsubscribe page during disconnect', [
                    'company_id' => $companyId,
                    'page_id' => $page->page_id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        $this->revokeAtMeta($connection);

        if ($purge) {
            $connection->delete();

            return;
        }

        $connection->forceFill([
            'status' => 'disconnected',
            'disconnected_at' => now(),
            'access_token' => null,
            'access_token_expires_at' => null,
            'granted_scopes' => null,
            'declined_scopes' => null,
            'last_error' => null,
        ])->save();

        MetaPage::where('meta_connection_id', $connection->id)->update([
            'is_active' => false,
            'access_token' => null,
            'webhook_subscribed_at' => null,
        ]);
    }

    /** Best-effort: a failure here must not block the local disconnect. */
    private function revokeAtMeta(MetaConnection $connection): void
    {
        if (! $connection->access_token) {
            return;
        }

        try {
            (new MetaGraphClient($connection))->delete('me/permissions');
        } catch (Throwable $exception) {
            Log::warning('[meta] token revocation at Meta failed', [
                'company_id' => $connection->company_id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function exchangeCode(string $code, ?string $appId, ?string $appSecret): string
    {
        try {
            $response = MetaGraphClient::withToken('')->get('oauth/access_token', [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'redirect_uri' => $this->redirectUri(),
                'code' => $code,
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['code' => ['Meta could not verify the authorization code.']]);
        }

        return $response['access_token'] ?? throw ValidationException::withMessages(['code' => ['Meta did not return an access token.']]);
    }

    private function exchangeForLongLivedToken(string $shortLivedToken, ?string $appId, ?string $appSecret): array
    {
        try {
            $response = MetaGraphClient::withToken('')->get('oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages(['code' => ['Meta could not issue a long-lived access token.']]);
        }

        return [$response['access_token'] ?? $shortLivedToken, $response['expires_in'] ?? null];
    }

    private function redirectUri(): string
    {
        return config('meta.redirect_uri') ?: rtrim(config('app.url'), '/').'/auth/facebook/callback';
    }
}
