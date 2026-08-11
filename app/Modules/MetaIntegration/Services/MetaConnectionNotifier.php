<?php

namespace App\Modules\MetaIntegration\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\MetaIntegration\Models\MetaConnection;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Collection;

/**
 * Tells a company when its Meta connection needs attention. Without this the
 * integration fails silently: the token dies, leads stop arriving, and nobody
 * finds out until someone asks where the leads went.
 */
class MetaConnectionNotifier
{
    private const ACTION_URL = '/integrations/meta';

    public function __construct(private readonly NotificationService $notifications) {}

    public function tokenExpiring(MetaConnection $connection): void
    {
        // One reminder per expiry window, not one per sweep.
        if ($connection->token_expiry_notified_at?->isAfter(now()->subDays(3))) {
            return;
        }

        $days = $connection->access_token_expires_at
            ? max(0, (int) ceil(now()->diffInHours($connection->access_token_expires_at, false) / 24))
            : 0;

        $this->push(
            $connection,
            'integration.meta.token_expiring',
            'Meta connection expires soon',
            "Your Facebook connection expires in {$days} day(s) and could not be renewed automatically. "
                .'Reconnect it to keep lead delivery, conversions and audience syncs running.',
            ['days_left' => $days, 'expires_at' => $connection->access_token_expires_at?->toISOString()],
            'warning',
        );

        $connection->forceFill(['token_expiry_notified_at' => now()])->save();
    }

    public function tokenExpired(MetaConnection $connection): void
    {
        $this->push(
            $connection,
            'integration.meta.disconnected',
            'Meta connection stopped working',
            'Your Facebook connection is no longer valid, so lead delivery, conversion events and audience '
                .'syncs have stopped. Reconnect the account to resume them.',
            ['last_error' => $connection->last_error],
            'critical',
        );
    }

    /** @param  array<int, string>  $missing */
    public function permissionsMissing(MetaConnection $connection, array $missing): void
    {
        $this->push(
            $connection,
            'integration.meta.permissions',
            'Meta permissions are incomplete',
            'The Facebook connection is missing '.implode(', ', $missing).'. Features that rely on them will '
                .'not work until the account is reconnected and the permissions accepted.',
            ['missing_scopes' => $missing],
            'warning',
        );
    }

    public function syncFailed(MetaConnection $connection, string $what, string $reason): void
    {
        $this->push(
            $connection,
            'integration.meta.sync_failed',
            "Meta {$what} sync failed",
            "The last {$what} synchronisation with Meta failed: {$reason}",
            ['reason' => $reason],
            'warning',
        );
    }

    public function leadReceived(MetaConnection $connection, string $contactName, ?string $formName): void
    {
        $this->push(
            $connection,
            'integration.meta.lead_received',
            'New lead from Meta',
            trim("{$contactName} came in".($formName ? " through {$formName}" : '').'.'),
            ['contact' => $contactName, 'form' => $formName],
            'info',
        );
    }

    private function push(MetaConnection $connection, string $event, string $title, string $message, array $payload, string $severity): void
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

    /** Company admins, plus anyone explicitly allowed to manage the integration. */
    private function recipients(MetaConnection $connection): Collection
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
