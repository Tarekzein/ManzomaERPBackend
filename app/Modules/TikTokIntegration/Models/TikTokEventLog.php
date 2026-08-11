<?php

namespace App\Modules\TikTokIntegration\Models;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TikTokEventLog extends Model
{
    protected $table = 'tiktok_event_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'extra_params' => 'array',
            'payload' => 'array',
            'response' => 'array',
            'next_retry_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TikTokConnection::class, 'tiktok_connection_id');
    }
}
