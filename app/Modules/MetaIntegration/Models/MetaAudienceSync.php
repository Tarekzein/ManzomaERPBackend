<?php

namespace App\Modules\MetaIntegration\Models;

use App\Modules\CRM\Models\CRMSegment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetaAudienceSync extends Model
{
    protected $table = 'meta_audience_syncs';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'approximate_count' => 'integer',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(MetaConnection::class, 'meta_connection_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CRMSegment::class, 'crm_segment_id');
    }
}
