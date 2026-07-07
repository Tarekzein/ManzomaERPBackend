<?php

namespace App\Modules\MetaIntegration\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MetaConnection extends Model
{
    protected $table = 'meta_connections';

    protected $guarded = [];

    protected $hidden = ['access_token', 'webhook_verify_token', 'app_secret'];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'app_secret' => 'encrypted',
            'webhook_verify_token' => 'encrypted',
            'access_token_expires_at' => 'datetime',
            'page_ids' => 'array',
            'scopes' => 'array',
            'require_consent' => 'boolean',
            'ldu_enabled' => 'boolean',
            'whatsapp_enabled' => 'boolean',
            'last_health_check_at' => 'datetime',
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

    public function isConnected(): bool
    {
        return $this->status === 'connected';
    }
}
