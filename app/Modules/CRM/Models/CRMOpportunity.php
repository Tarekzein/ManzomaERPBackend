<?php

namespace App\Modules\CRM\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class CRMOpportunity extends Model
{
    use SoftDeletes;

    protected $table = 'crm_opportunities';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'expected_close_date' => 'date',
            'won_at' => 'datetime',
            'lost_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function contact()
    {
        return $this->belongsTo(CRMContact::class, 'contact_id');
    }

    public function stage()
    {
        return $this->belongsTo(CRMPipelineStage::class, 'stage_id');
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function activities()
    {
        return $this->hasMany(CRMActivity::class, 'opportunity_id');
    }

    public function tasks()
    {
        return $this->hasMany(CRMTask::class, 'opportunity_id');
    }

    public function notes()
    {
        return $this->hasMany(CRMNote::class, 'opportunity_id');
    }
}
