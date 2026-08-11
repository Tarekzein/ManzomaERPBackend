<?php

namespace App\Modules\TikTokIntegration\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMSegment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TikTokAudienceSync extends Model
{
    protected $table = 'tiktok_audience_syncs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TikTokConnection::class, 'tiktok_connection_id');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(CRMSegment::class, 'crm_segment_id');
    }
}
