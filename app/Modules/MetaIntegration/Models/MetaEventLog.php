<?php

namespace App\Modules\MetaIntegration\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MetaEventLog extends Model
{
    protected $table = 'meta_event_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'response_body' => 'array',
            'attempts' => 'integer',
            'next_retry_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function related(): MorphTo
    {
        return $this->morphTo('related');
    }

    public function isRetryEligible(): bool
    {
        return in_array($this->status, ['pending', 'failed'], true)
            && ($this->next_retry_at === null || $this->next_retry_at->isPast());
    }
}
