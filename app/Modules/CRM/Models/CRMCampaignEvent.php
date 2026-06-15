<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class CRMCampaignEvent extends Model
{
    protected $table = 'crm_campaign_events';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'occurred_at' => 'datetime'];
    }

    public function campaign()
    {
        return $this->belongsTo(CRMCampaign::class, 'campaign_id');
    }

    public function contact()
    {
        return $this->belongsTo(CRMContact::class, 'contact_id');
    }
}
