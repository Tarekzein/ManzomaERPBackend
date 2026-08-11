<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\TikTokIntegration\Models\TikTokAdvertiser;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Illuminate\Support\Str;

/**
 * Every company registers its own TikTok for Business app, so this hands them
 * the values to configure it with and tracks what is still outstanding —
 * the same contract as the Meta setup guide.
 */
class TikTokSetupService
{
    /** @return array<string, mixed> */
    public function instructions(int $companyId): array
    {
        $connection = TikTokConnection::where('company_id', $companyId)->first();

        return [
            'app' => [
                'app_id' => $connection?->app_id,
                'has_app_secret' => filled($connection?->app_secret),
                'connection_method' => $connection?->connection_method,
            ],
            'oauth' => [
                'redirect_uri' => $this->redirectUri(),
                'scopes' => config('tiktok.scopes'),
                'required_scopes' => config('tiktok.required_scopes'),
            ],
            'webhook' => [
                // Lead Ads use scheduled export tasks; this module does not
                // currently register a TikTok webhook receiver.
                'supported' => false,
                'callback_url' => null,
                'verify_token' => $connection?->webhook_verify_token,
            ],
            'status' => $this->checklist($connection),
        ];
    }

    /** @return array<int, array{step: string, label: string, done: bool, detail: ?string}> */
    public function checklist(?TikTokConnection $connection): array
    {
        $advertisers = $connection
            ? TikTokAdvertiser::where('tiktok_connection_id', $connection->id)->active()->count()
            : 0;

        $missingScopes = $connection && $connection->granted_scopes !== null
            ? array_values(array_diff((array) config('tiktok.required_scopes'), $connection->granted_scopes))
            : [];

        return [
            [
                'step' => 'credentials',
                'label' => 'Save your TikTok App ID and secret',
                'done' => (bool) $connection?->hasAppCredentials(),
                'detail' => null,
            ],
            [
                'step' => 'redirect_uri',
                'label' => 'Add the redirect URI to your app\'s login settings',
                'done' => in_array($connection?->status, ['connected', 'degraded'], true),
                'detail' => $this->redirectUri(),
            ],
            [
                'step' => 'authorize',
                'label' => 'Connect the TikTok Business account',
                'done' => in_array($connection?->status, ['connected', 'degraded'], true),
                'detail' => $connection?->status,
            ],
            [
                'step' => 'permissions',
                'label' => 'Approve the scopes the features need',
                'done' => $connection?->granted_scopes !== null && $missingScopes === [],
                'detail' => $missingScopes ? 'Missing: '.implode(', ', $missingScopes) : null,
            ],
            [
                'step' => 'advertisers',
                'label' => 'Sync your advertiser accounts',
                'done' => $advertisers > 0,
                'detail' => $advertisers > 0 ? $advertisers.' advertiser account(s)' : null,
            ],
            [
                'step' => 'events',
                'label' => 'Enable conversion events and set the pixel code',
                'done' => (bool) $connection?->events_enabled && filled($connection?->pixel_code),
                'detail' => $connection?->pixel_code,
            ],
        ];
    }

    public function rotateVerifyToken(TikTokConnection $connection): string
    {
        $token = Str::random(40);
        $connection->forceFill(['webhook_verify_token' => $token])->save();

        return $token;
    }

    private function redirectUri(): string
    {
        return config('tiktok.redirect_uri') ?: rtrim((string) config('app.url'), '/').'/auth/tiktok/callback';
    }
}
