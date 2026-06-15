<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class CRMPipelineStage extends Model
{
    protected $table = 'crm_pipeline_stages';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_won' => 'boolean', 'is_lost' => 'boolean'];
    }

    public function opportunities()
    {
        return $this->hasMany(CRMOpportunity::class, 'stage_id');
    }
}
