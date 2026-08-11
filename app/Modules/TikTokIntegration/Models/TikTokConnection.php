<?php

namespace App\Modules\TikTokIntegration\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\TikTokIntegration\Exceptions\MissingAppCredentialsException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TikTokConnection extends Model
{
    protected $table = 'tiktok_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token', 'app_secret', 'webhook_verify_token', 'webhook_verify_token_hash'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'scopes' => 'array',
            'granted_scopes' => 'array',
            'events_enabled' => 'boolean',
            'access_token_expires_at' => 'datetime',
            'refresh_token_expires_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
            'token_expiry_notified_at' => 'datetime',
            'scopes_checked_at' => 'datetime',
            'last_health_check_at' => 'datetime',
            'disconnected_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (TikTokConnection $connection) {
            if ($connection->isDirty('webhook_verify_token')) {
                $connection->webhook_verify_token_hash = $connection->webhook_verify_token
                    ? hash('sha256', $connection->webhook_verify_token)
                    : null;
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function advertisers(): HasMany
    {
        return $this->hasMany(TikTokAdvertiser::class, 'tiktok_connection_id');
    }

    public function eventMappings(): HasMany
    {
        return $this->hasMany(TikTokEventMapping::class, 'tiktok_connection_id');
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(TikTokEventLog::class, 'tiktok_connection_id');
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }

    public function hasAppCredentials(): bool
    {
        return filled($this->app_id) && filled($this->app_secret);
    }

    /**
     * The company's own TikTok App id. Every tenant runs their own app, so
     * there is deliberately no platform-wide fallback.
     */
    public function appId(): string
    {
        return $this->app_id ?: throw MissingAppCredentialsException::forCompany((int) $this->company_id);
    }

    public function appSecret(): string
    {
        return $this->app_secret ?: throw MissingAppCredentialsException::forCompany((int) $this->company_id);
    }

    /**
     * TikTok access tokens carry an expiry and a refresh token. A connection is
     * only recoverable while the refresh token itself is still valid.
     */
    public function tokenExpiresWithin(int $days): bool
    {
        return $this->access_token_expires_at !== null
            && $this->access_token_expires_at->isBefore(now()->addDays($days));
    }

    public function canRefresh(): bool
    {
        return filled($this->refresh_token)
            && ($this->refresh_token_expires_at === null || $this->refresh_token_expires_at->isFuture());
    }
}
