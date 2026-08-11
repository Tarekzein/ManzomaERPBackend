<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\MetaIntegration\Models\MetaPage;
use Illuminate\Support\Str;

/**
 * Every company runs its own Meta App, so each one has to configure that app
 * themselves: the OAuth redirect URI, the webhook callback and its verify
 * token, and the permissions to request. This assembles exactly those values
 * plus a checklist of what is still outstanding.
 */
class MetaSetupService
{
    /** @return array<string, mixed> */
    public function instructions(int $companyId): array
    {
        $connection = MetaConnection::where('company_id', $companyId)->first();

        // The verify token is generated with the app credentials; a company that
        // has not saved them yet has nothing to paste anywhere.
        return [
            'app' => [
                'app_id' => $connection?->app_id,
                'has_app_secret' => filled($connection?->app_secret),
                'oauth_config_id' => $connection?->oauth_config_id,
                'connection_method' => $connection?->connection_method,
            ],
            'oauth' => [
                'redirect_uri' => $this->redirectUri(),
                'scopes' => config('meta.scopes'),
                'required_scopes' => config('meta.required_scopes'),
            ],
            'webhook' => [
                'callback_url' => route('meta.webhooks.leadgen.receive'),
                // Shown deliberately: the company must paste it into their own
                // Meta App. Reading it requires meta.view on this company.
                'verify_token' => $connection?->webhook_verify_token,
                'page_fields' => config('meta.webhook_page_fields'),
            ],
            'status' => $this->checklist($connection),
        ];
    }

    /**
     * What is done and what is not, in the order a company works through it.
     *
     * @return array<int, array{step: string, label: string, done: bool, detail: ?string}>
     */
    public function checklist(?MetaConnection $connection): array
    {
        $pages = $connection
            ? MetaPage::where('meta_connection_id', $connection->id)->active()->get()
            : collect();

        $missingScopes = $connection && $connection->granted_scopes !== null
            ? array_values(array_diff((array) config('meta.required_scopes'), $connection->granted_scopes))
            : [];

        return [
            [
                'step' => 'credentials',
                'label' => 'Save your Meta App ID and secret',
                'done' => filled($connection?->app_id) && filled($connection?->app_secret),
                'detail' => null,
            ],
            [
                'step' => 'redirect_uri',
                'label' => 'Add the redirect URI to your app\'s Facebook Login settings',
                'done' => $connection?->status === 'connected' || $connection?->status === 'degraded',
                'detail' => $this->redirectUri(),
            ],
            [
                'step' => 'authorize',
                'label' => 'Connect the Facebook account',
                'done' => in_array($connection?->status, ['connected', 'degraded'], true),
                'detail' => $connection?->status,
            ],
            [
                'step' => 'permissions',
                'label' => 'Grant the permissions the features need',
                'done' => $connection?->granted_scopes !== null && $missingScopes === [],
                'detail' => $missingScopes ? 'Missing: '.implode(', ', $missingScopes) : null,
            ],
            [
                'step' => 'webhook',
                'label' => 'Point your app\'s webhook at this workspace',
                'done' => $pages->contains(fn (MetaPage $page) => $page->isSubscribed()),
                'detail' => route('meta.webhooks.leadgen.receive'),
            ],
            [
                'step' => 'pages',
                'label' => 'Sync and subscribe your Pages',
                'done' => $pages->isNotEmpty(),
                'detail' => $pages->isNotEmpty() ? $pages->count().' page(s) connected' : null,
            ],
        ];
    }

    /**
     * Issue a new verify token. The company must update their Meta App with it,
     * so the old one keeps working only until they do.
     */
    public function rotateVerifyToken(MetaConnection $connection): string
    {
        $token = Str::random(40);

        $connection->forceFill(['webhook_verify_token' => $token])->save();

        return $token;
    }

    private function redirectUri(): string
    {
        return config('meta.redirect_uri') ?: rtrim(config('app.url'), '/').'/auth/facebook/callback';
    }
}
