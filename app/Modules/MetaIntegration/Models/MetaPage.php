<?php

namespace App\Modules\MetaIntegration\Models;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Facebook Page the company has connected, plus the Instagram Business
 * account linked to it. The page access token is stored here because webhook
 * subscription and lead reads need one; it is encrypted and never serialised.
 */
class MetaPage extends Model
{
    protected $table = 'meta_pages';

    protected $guarded = [];

    protected $hidden = ['access_token'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'tasks' => 'array',
            'webhook_fields' => 'array',
            'is_active' => 'boolean',
            'webhook_subscribed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected $appends = ['has_instagram', 'webhook_subscribed'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasInstagram(): bool
    {
        return filled($this->instagram_account_id);
    }

    public function isSubscribed(): bool
    {
        return $this->webhook_subscribed_at !== null;
    }

    public function getHasInstagramAttribute(): bool
    {
        return $this->hasInstagram();
    }

    public function getWebhookSubscribedAttribute(): bool
    {
        return $this->isSubscribed();
    }
}
