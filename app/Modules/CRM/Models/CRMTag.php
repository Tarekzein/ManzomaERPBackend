<?php

namespace App\Modules\CRM\Models;

use Illuminate\Database\Eloquent\Model;

class CRMTag extends Model
{
    protected $table = 'crm_tags';

    protected $guarded = [];

    public function contacts()
    {
        return $this->belongsToMany(CRMContact::class, 'crm_contact_tag', 'tag_id', 'contact_id');
    }
}
