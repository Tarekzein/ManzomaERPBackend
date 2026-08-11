<?php

namespace App\Modules\TikTokIntegration\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\TikTokIntegration\Models\TikTokConnection;
use Illuminate\Support\Collection;

/** Keeps company admins informed about the TikTok connection's health. */
class TikTokConnectionNotifier
{
    private const ACTION_URL = '/integrations/tiktok';

    public function __construct(private readonly NotificationService $notifications) {}

    public function tokenExpiring(TikTokConnection $connection): void
    {
        if ($connection->token_expiry_notified_at?->isAfter(now()->subDays(3))) {
            return;
        }

        $this->push(
            $connection,
            'integration.tiktok.token_expiring',
            'TikTok connection expires soon',
            'Your TikTok connection could not be renewed automatically. Reconnect it to keep campaign '
                .'reporting, lead delivery and conversion events running.',
            ['expires_at' => $connection->access_token_expires_at?->toISOString()],
            'warning',
        );

        $connection->forceFill(['token_expiry_notified_at' => now()])->save();
    }

    public function tokenExpired(TikTokConnection $connection): void
    {
        $this->push(
            $connection,
            'integration.tiktok.disconnected',
            'TikTok connection stopped working',
            'Your TikTok connection is no longer valid, so campaign data, leads and conversion events have '
                .'stopped syncing. Reconnect the account to resume them.',
            ['last_error' => $connection->last_error],
            'critical',
        );
    }

    /** @param  array<int, string>  $missing */
    public function permissionsMissing(TikTokConnection $connection, array $missing): void
    {
        $this->push(
            $connection,
            'integration.tiktok.permissions',
            'TikTok permissions are incomplete',
            'The TikTok connection is missing '.implode(', ', $missing).'. Features relying on them will not '
                .'work until the account is reconnected with those scopes approved.',
            ['missing_scopes' => $missing],
            'warning',
        );
    }

    public function syncFailed(TikTokConnection $connection, string $what, string $reason): void
    {
        $this->push(
            $connection,
            'integration.tiktok.sync_failed',
            "TikTok {$what} sync failed",
            "The last {$what} synchronisation with TikTok failed: {$reason}",
            ['reason' => $reason],
            'warning',
        );
    }

    private function push(TikTokConnection $connection, string $event, string $title, string $message, array $payload, string $severity): void
    {
        $recipients = $this->recipients($connection);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notifications->send(
            $recipients,
            $event,
            $title,
            $message,
            $payload + ['company_id' => $connection->company_id],
            self::ACTION_URL,
            $severity,
        );
    }

    private function recipients(TikTokConnection $connection): Collection
    {
        $admins = User::query()
            ->where('company_id', $connection->company_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::CompanyAdmin->value))
            ->get();

        return $admins->isNotEmpty()
            ? $admins
            : User::query()->where('company_id', $connection->company_id)->where('is_active', true)->limit(1)->get();
    }
}
