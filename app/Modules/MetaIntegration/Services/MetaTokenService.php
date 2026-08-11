<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\MetaIntegration\Exceptions\MetaGraphException;
use App\Modules\MetaIntegration\Models\MetaConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Owns the access-token lifecycle.
 *
 * Meta issues no refresh tokens. A long-lived *user* token lasts ~60 days and
 * can only be extended by re-exchanging it while it is still valid, so a
 * connection left alone for two months dies silently. This service re-exchanges
 * tokens before that happens, verifies what Meta actually granted, and moves the
 * connection through explicit states so the UI and notifications can react.
 *
 * A *system user* token (Business Manager) never expires and is the better
 * choice for server-to-server work; those are detected and skipped.
 */
class MetaTokenService
{
    public function __construct(private readonly MetaConnectionNotifier $notifier) {}

    /**
     * Inspect the token: expiry, type, and the scopes Meta really granted.
     *
     * @return array{valid: bool, expires_at: ?Carbon, type: string, scopes: array}
     */
    public function inspect(MetaConnection $connection): array
    {
        // The company's own app credentials: there is no shared platform app.
        $appId = $connection->appId();
        $appSecret = $connection->appSecret();

        $response = MetaGraphClient::withToken("{$appId}|{$appSecret}")->get('debug_token', [
            'input_token' => $connection->access_token,
        ]);

        $data = $response['data'] ?? [];
        $expiresAt = ! empty($data['expires_at']) ? now()->setTimestamp((int) $data['expires_at']) : null;

        return [
            'valid' => (bool) ($data['is_valid'] ?? false),
            // expires_at = 0 means "never", which is how system user tokens report.
            'expires_at' => $expiresAt,
            'type' => ($data['type'] ?? '') === 'SYSTEM_USER' || $expiresAt === null ? 'system_user' : 'user',
            'scopes' => $data['scopes'] ?? [],
        ];
    }

    /**
     * Re-exchange a long-lived token for a fresh 60-day one. Meta returns a new
     * token only while the current one is still valid.
     */
    public function refresh(MetaConnection $connection): bool
    {
        if ($connection->token_type === 'system_user') {
            return false; // Never expires; nothing to refresh.
        }

        if (! $connection->hasAppCredentials() || ! $connection->access_token) {
            return false;
        }

        $appId = $connection->appId();
        $appSecret = $connection->appSecret();

        try {
            $response = MetaGraphClient::withToken('')->get('oauth/access_token', [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $connection->access_token,
            ]);
        } catch (MetaGraphException $exception) {
            $this->markUnusable($connection, $exception);

            return false;
        }

        if (empty($response['access_token'])) {
            return false;
        }

        $connection->forceFill([
            'access_token' => $response['access_token'],
            'access_token_expires_at' => isset($response['expires_in'])
                ? now()->addSeconds((int) $response['expires_in'])
                : null,
            'token_refreshed_at' => now(),
            'token_expiry_notified_at' => null,
            'status' => 'connected',
            'last_error' => null,
        ])->save();

        Log::info('[meta] access token refreshed', [
            'company_id' => $connection->company_id,
            'expires_at' => $connection->access_token_expires_at?->toISOString(),
        ]);

        return true;
    }

    /**
     * Record what Meta granted versus what the app asked for. A connection
     * missing a required scope is "degraded": it authenticates but the features
     * relying on that scope will fail.
     *
     * @return array{granted: array, declined: array, missing: array}
     */
    public function syncPermissions(MetaConnection $connection): array
    {
        $response = (new MetaGraphClient($connection))->get('me/permissions');

        $granted = [];
        $declined = [];

        foreach ($response['data'] ?? [] as $permission) {
            $name = $permission['permission'] ?? null;
            if (! $name) {
                continue;
            }

            ($permission['status'] ?? '') === 'granted'
                ? $granted[] = $name
                : $declined[] = $name;
        }

        // No entries at all means Graph told us nothing useful — treat it as
        // unknown rather than concluding the user declined everything.
        if (! $granted && ! $declined) {
            return ['granted' => [], 'declined' => [], 'missing' => []];
        }

        $missing = array_values(array_diff($this->requiredScopes($connection), $granted));

        $connection->forceFill([
            'granted_scopes' => $granted,
            'declined_scopes' => $declined,
            'scopes_checked_at' => now(),
            'status' => $missing ? 'degraded' : ($connection->status === 'degraded' ? 'connected' : $connection->status),
        ])->save();

        if ($missing) {
            $this->notifier->permissionsMissing($connection->refresh(), $missing);
        }

        return ['granted' => $granted, 'declined' => $declined, 'missing' => $missing];
    }

    /**
     * Refresh + validate a connection, notifying when it needs a human.
     *
     * @return string the outcome, for the command's summary
     */
    public function maintain(MetaConnection $connection): string
    {
        if (in_array($connection->status, ['disconnected', 'pending'], true) || ! $connection->access_token) {
            return 'skipped';
        }

        try {
            $inspection = $this->inspect($connection);
        } catch (MetaGraphException $exception) {
            $this->markUnusable($connection, $exception);

            return 'expired';
        }

        $connection->forceFill([
            'token_type' => $inspection['type'],
            'access_token_expires_at' => $inspection['expires_at'],
        ])->save();

        if (! $inspection['valid']) {
            $this->markUnusable($connection, null, 'The Meta access token is no longer valid.');

            return 'expired';
        }

        if ($inspection['type'] === 'system_user') {
            $this->syncPermissions($connection);

            return 'permanent';
        }

        $leadDays = (int) config('meta.token_refresh_lead_days', 7);

        if ($connection->tokenExpiresWithin($leadDays)) {
            if ($this->refresh($connection)) {
                $this->syncPermissions($connection->refresh());

                return 'refreshed';
            }

            // Could not extend it: the customer has to reconnect before it dies.
            $this->notifier->tokenExpiring($connection->refresh());

            return 'expiring';
        }

        $this->syncPermissions($connection);

        return 'healthy';
    }

    /** The token is dead or revoked: stop using it and tell the company. */
    public function markUnusable(MetaConnection $connection, ?MetaGraphException $exception = null, ?string $message = null): void
    {
        $wasUsable = $connection->status !== 'expired';

        $connection->forceFill([
            'status' => 'expired',
            'last_error' => $message ?? $exception?->getMessage(),
        ])->save();

        Log::warning('[meta] connection can no longer call the Graph API', [
            'company_id' => $connection->company_id,
            'error_code' => $exception?->errorCode(),
            'message' => $message ?? $exception?->getMessage(),
        ]);

        if ($wasUsable) {
            $this->notifier->tokenExpired($connection->refresh());
        }
    }

    /** @return array<int, string> */
    private function requiredScopes(MetaConnection $connection): array
    {
        $required = (array) config('meta.required_scopes', []);

        // WhatsApp scopes only matter to companies using WhatsApp.
        if (! $connection->whatsapp_enabled) {
            $required = array_values(array_filter(
                $required,
                fn (string $scope) => ! str_starts_with($scope, 'whatsapp_'),
            ));
        }

        return $required;
    }
}
