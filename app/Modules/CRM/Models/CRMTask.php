<?php

namespace App\Modules\CRM\Models;

use App\Modules\Authentication\Models\User;
use Illuminate\Database\Eloquent\Model;

class CRMTask extends Model
{
    protected $table = 'crm_tasks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'reminder_at' => 'datetime',
            'completed_at' => 'datetime',
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

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
