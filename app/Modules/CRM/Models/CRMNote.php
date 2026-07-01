<?php

namespace App\Modules\CRM\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class CRMNote extends Model
{
    protected $table = 'crm_notes';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
        ];
    }

    public function contact()
    {
        return $this->belongsTo(CRMContact::class, 'contact_id');
    }

    public function opportunity()
    {
        return $this->belongsTo(CRMOpportunity::class, 'opportunity_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
