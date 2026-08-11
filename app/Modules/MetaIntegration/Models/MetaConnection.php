<?php

namespace App\Modules\MetaIntegration\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\MetaIntegration\Exceptions\MissingAppCredentialsException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaConnection extends Model
{
    protected $table = 'meta_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'webhook_verify_token', 'webhook_verify_token_hash', 'app_secret'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'page_ids' => 'array',
            'scopes' => 'array',
            'granted_scopes' => 'array',
            'declined_scopes' => 'array',
            'scopes_checked_at' => 'datetime',
            'disconnected_at' => 'datetime',
            'require_consent' => 'boolean',
            'ldu_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'last_health_check_at' => 'datetime',
            'token_refreshed_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by');
    }

    public function eventMappings(): HasMany
    {
        return $this->hasMany(MetaEventMapping::class);
    }

    public function eventLogs(): HasMany
    {
        return $this->hasMany(MetaEventLog::class);
    }

    public function leadFormMappings(): HasMany
    {
        return $this->hasMany(MetaLeadFormMapping::class);
    }

    public function audienceSyncs(): HasMany
    {
        return $this->hasMany(MetaAudienceSync::class);
    }

    protected static function booted(): void
    {
        // The token itself is encrypted (non-deterministic), so webhooks match
        // on this hash instead of decrypting every row.
        static::saving(function (MetaConnection $connection) {
            if ($connection->isDirty('webhook_verify_token')) {
                $connection->webhook_verify_token_hash = $connection->webhook_verify_token
                    ? hash('sha256', $connection->webhook_verify_token)
                    : null;
            }
        });
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
     * The company's own Meta App id. Every tenant runs their own app, so there
     * is deliberately no platform-wide fallback.
     */
    public function appId(): string
    {
        return $this->app_id ?: throw MissingAppCredentialsException::forCompany((int) $this->company_id);
    }

    public function appSecret(): string
    {
        return $this->app_secret ?: throw MissingAppCredentialsException::forCompany((int) $this->company_id);
    }

    public function tokenHasExpired(): bool
    {
        return $this->access_token_expires_at !== null && $this->access_token_expires_at->isPast();
    }

    /** Long-lived Meta tokens last ~60 days; refresh well before that. */
    public function tokenExpiresWithin(int $days): bool
    {
        return $this->access_token_expires_at !== null
            && $this->access_token_expires_at->isBefore(now()->addDays($days));
    }
}
