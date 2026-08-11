<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\TikTokIntegration\Exceptions\TikTokApiException;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Illuminate\Support\Facades\Log;

/**
 * TikTok token lifecycle.
 *
 * Unlike Meta, TikTok issues a refresh token, so an expiring connection can be
 * renewed unattended — until the refresh token itself lapses, which is the point
 * where a human has to reconnect.
 */
class TikTokTokenService
{
    public function __construct(private readonly TikTokConnectionNotifier $notifier) {}

    public function refresh(TikTokConnection $connection): bool
    {
        if (! $connection->canRefresh()) {
            return false;
        }

        $appId = $connection->appId();
        $appSecret = $connection->appSecret();

        try {
            $data = TikTokClient::withToken(null)->post('oauth2/refresh_token', [
                'app_id' => $appId,
                'secret' => $appSecret,
                'refresh_token' => $connection->refresh_token,
                'grant_type' => 'refresh_token',
            ]);
        } catch (TikTokApiException $exception) {
            $this->markUnusable($connection, $exception->getMessage());

            return false;
        }

        if (empty($data['access_token'])) {
            return false;
        }

        $connection->forceFill([
            'access_token' => $data['access_token'],
            // TikTok rotates the refresh token on each use; keep the new one.
            'refresh_token' => $data['refresh_token'] ?? $connection->refresh_token,
            'access_token_expires_at' => isset($data['access_token_expire_in'])
                ? now()->addSeconds((int) $data['access_token_expire_in'])
                : null,
            'refresh_token_expires_at' => isset($data['refresh_token_expire_in'])
                ? now()->addSeconds((int) $data['refresh_token_expire_in'])
                : $connection->refresh_token_expires_at,
            'token_refreshed_at' => now(),
            'token_expiry_notified_at' => null,
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        Log::info('[tiktok] access token refreshed', [
            'company_id' => $connection->company_id,
            'expires_at' => $connection->access_token_expires_at?->toISOString(),
        ]);

        return true;
    }

    /**
     * Compare granted scopes with what the features need. TikTok reports scopes
     * only at authorisation time, so this reads what was stored then.
     *
     * @return array<int, string> the missing scopes
     */
    public function evaluatePermissions(TikTokConnection $connection): array
    {
        $granted = $connection->granted_scopes ?? [];

        if (! $granted) {
            return [];
        }

        $missing = array_values(array_diff((array) config('tiktok.required_scopes'), $granted));

        $connection->forceFill([
            'status' => $missing ? 'degraded' : ($connection->status === 'degraded' ? 'connected' : $connection->status),
            'scopes_checked_at' => now(),
        ])->save();

        if ($missing) {
            $this->notifier->permissionsMissing($connection->refresh(), $missing);
        }

        return $missing;
    }

    /** @return string outcome for the maintenance command */
    public function maintain(TikTokConnection $connection): string
    {
        if (in_array($connection->status, ['disconnected', 'pending'], true) || ! $connection->access_token) {
            return 'skipped';
        }

        $leadDays = (int) config('tiktok.token_refresh_lead_days', 7);

        if ($connection->access_token_expires_at === null) {
            // No expiry reported: verify the token still works.
            return $this->verify($connection) ? 'healthy' : 'expired';
        }

        if ($connection->access_token_expires_at->isPast()) {
            if ($this->refresh($connection)) {
                return 'refreshed';
            }

            $this->markUnusable($connection, 'The TikTok access token expired and could not be refreshed.');

            return 'expired';
        }

        if ($connection->tokenExpiresWithin($leadDays)) {
            if ($this->refresh($connection)) {
                return 'refreshed';
            }

            $this->notifier->tokenExpiring($connection->refresh());

            return 'expiring';
        }

        $this->evaluatePermissions($connection);

        return 'healthy';
    }

    /** Cheap liveness probe: list advertisers the token can see. */
    public function verify(TikTokConnection $connection): bool
    {
        try {
            (new TikTokClient($connection))->get('oauth2/advertiser/get', [
                'app_id' => $connection->appId(),
                'secret' => $connection->appSecret(),
            ]);
        } catch (TikTokApiException $exception) {
            if ($exception->isAuthFailure()) {
                $this->markUnusable($connection, $exception->getMessage());

                return false;
            }

            // A transient fault is not evidence the token is dead.
            return true;
        }

        $connection->forceFill(['last_health_check_at' => now(), 'last_error' => null])->save();

        return true;
    }

    public function markUnusable(TikTokConnection $connection, string $message): void
    {
        $wasUsable = $connection->status !== 'expired';

        $connection->forceFill(['status' => 'expired', 'last_error' => $message])->save();

        Log::warning('[tiktok] connection can no longer call the API', [
            'company_id' => $connection->company_id,
            'message' => $message,
        ]);

        if ($wasUsable) {
            $this->notifier->tokenExpired($connection->refresh());
        }
    }
}
