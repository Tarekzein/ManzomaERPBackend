<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class CRMSegment extends Model
{
    protected $table = 'crm_segments';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['criteria' => 'array'];
    }
}
