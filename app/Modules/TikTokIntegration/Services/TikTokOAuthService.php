<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * TikTok for Business OAuth.
 *
 * The flow mirrors Meta's, with one meaningful difference: TikTok returns a
 * refresh token, so an expiring connection can be renewed without the customer
 * re-authorising.
 */
class TikTokOAuthService
{
    private const STATE_PREFIX = 'oauth.tiktok.';

    public function __construct(
        private readonly TikTokAdvertiserService $advertisers,
        private readonly TikTokTokenService $tokens,
    ) {}

    public function saveAppCredentials(int $companyId, User $user, string $appId, string $appSecret): TikTokConnection
    {
        $existing = TikTokConnection::where('company_id', $companyId)->first();

        return TikTokConnection::updateOrCreate(
            ['company_id' => $companyId],
            [
                'app_id' => $appId,
                'app_secret' => $appSecret,
                'connection_method' => 'oauth',
                'status' => 'pending',
                'connected_by' => $user->id,
                'webhook_verify_token' => $existing?->webhook_verify_token ?: Str::random(40),
            ],
        );
    }

    /** @return array{url: string, state: string, expires_in: int} */
    public function authorizationUrl(int $companyId, User $user): array
    {
        $connection = TikTokConnection::where('company_id', $companyId)->first();

        // Every company connects their own TikTok app; nothing is shared.
        if (! $connection?->hasAppCredentials()) {
            throw ValidationException::withMessages([
                'app_id' => ['Save your TikTok App ID and secret before connecting.'],
            ]);
        }

        $appId = $connection->appId();

        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.$state, [
            'company_id' => $companyId,
            'user_id' => $user->id,
        ], now()->addMinutes(10));

        $params = [
            'app_id' => $appId,
            'state' => $state,
            'redirect_uri' => $this->redirectUri(),
        ];

        return [
            'url' => rtrim((string) config('tiktok.auth_url'), '/').'?'.http_build_query($params),
            'state' => $state,
            'expires_in' => 600,
        ];
    }

    public function handleCallback(string $authCode, string $state): TikTokConnection
    {
        $payload = Cache::pull(self::STATE_PREFIX.$state);

        if (! $payload) {
            throw ValidationException::withMessages(['state' => ['The TikTok connection session has expired.']]);
        }

        $connection = TikTokConnection::where('company_id', $payload['company_id'])->first();

        if (! $connection?->hasAppCredentials()) {
            throw ValidationException::withMessages([
                'app_id' => ['Save your TikTok App ID and secret before connecting.'],
            ]);
        }

        $appId = $connection->appId();
        $appSecret = $connection->appSecret();

        try {
            $data = TikTokClient::withToken(null)->post('oauth2/access_token', [
                'app_id' => $appId,
                'secret' => $appSecret,
                'auth_code' => $authCode,
                'grant_type' => 'authorization_code',
            ]);
        } catch (TikTokApiException $exception) {
            throw ValidationException::withMessages([
                'auth_code' => ['TikTok rejected the authorization code: '.$exception->getMessage()],
            ]);
        }

        if (empty($data['access_token'])) {
            throw ValidationException::withMessages(['auth_code' => ['TikTok did not return an access token.']]);
        }

        $connection = TikTokConnection::updateOrCreate(
            ['company_id' => $payload['company_id']],
            [
                'connection_method' => 'oauth',
                'status' => 'connected',
                'access_token' => $data['access_token'],
                'refresh_token' => $data['refresh_token'] ?? null,
                'access_token_expires_at' => isset($data['access_token_expire_in'])
                    ? now()->addSeconds((int) $data['access_token_expire_in'])
                    : null,
                'refresh_token_expires_at' => isset($data['refresh_token_expire_in'])
                    ? now()->addSeconds((int) $data['refresh_token_expire_in'])
                    : null,
                'token_refreshed_at' => now(),
                'token_expiry_notified_at' => null,
                'core_user_id' => $data['core_user_id'] ?? null,
                'scopes' => config('tiktok.scopes'),
                // TikTok returns the granted scope list on the token response.
                'granted_scopes' => $this->normaliseScopes($data['scope'] ?? null),
                'scopes_checked_at' => now(),
                'connected_by' => $payload['user_id'],
                'last_error' => null,
                'disconnected_at' => null,
                'last_health_check_at' => now(),
            ],
        );

        // Advertiser accounts make the connection usable; a failure here should
        // not undo an otherwise successful authorisation.
        try {
            $this->advertisers->sync($connection);
        } catch (Throwable $exception) {
            Log::warning('[tiktok] advertiser sync after connect failed', [
                'company_id' => $connection->company_id,
                'message' => $exception->getMessage(),
            ]);
        }

        $this->tokens->evaluatePermissions($connection);

        return $connection->refresh();
    }

    /**
     * TikTok has no token-revocation endpoint, so disconnecting clears the
     * credentials locally and keeps the history, as the Meta module does.
     */
    public function disconnect(int $companyId, bool $purge = false): void
    {
        $connection = TikTokConnection::where('company_id', $companyId)->first();

        if (! $connection) {
            return;
        }

        if ($purge) {
            $connection->delete();

            return;
        }

        $connection->forceFill([
            'status' => 'disconnected',
            'disconnected_at' => now(),
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'refresh_token_expires_at' => null,
            'granted_scopes' => null,
            'last_error' => null,
        ])->save();

        $connection->advertisers()->update(['is_active' => false]);
    }

    /** @return array<int, string> */
    private function normaliseScopes(mixed $scope): array
    {
        if (is_array($scope)) {
            return array_values(array_map('strval', $scope));
        }

        if (is_string($scope) && $scope !== '') {
            $decoded = json_decode($scope, true);

            return is_array($decoded) ? array_values(array_map('strval', $decoded)) : explode(',', $scope);
        }

        return [];
    }

    private function redirectUri(): string
    {
        return config('tiktok.redirect_uri') ?: rtrim((string) config('app.url'), '/').'/auth/tiktok/callback';
    }
}
