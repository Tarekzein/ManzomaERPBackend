<?php

namespace App\Modules\CRM\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Sales\Models\SalesContact;
use Illuminate\Database\Eloquent\Model;

class CRMContact extends Model
{
    protected $table = 'crm_contacts';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'address' => 'array',
            'custom_attributes' => 'array',
            'converted_at' => 'datetime',
        ];
    }

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function salesContact()
    {
        return $this->belongsTo(SalesContact::class);
    }

    public function tags()
    {
        return $this->belongsToMany(CRMTag::class, 'crm_contact_tag', 'contact_id', 'tag_id');
    }

    public function opportunities()
    {
        return $this->hasMany(CRMOpportunity::class, 'contact_id');
    }

    public function activities()
    {
        return $this->hasMany(CRMActivity::class, 'contact_id');
    }

    public function tasks()
    {
        return $this->hasMany(CRMTask::class, 'contact_id');
    }
}
