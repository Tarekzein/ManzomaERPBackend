<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class CRMCampaign extends Model
{
    protected $table = 'crm_campaigns';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['scheduled_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function segment()
    {
        return $this->belongsTo(CRMSegment::class, 'segment_id');
    }

    public function events()
    {
        return $this->hasMany(CRMCampaignEvent::class, 'campaign_id');
    }
}
