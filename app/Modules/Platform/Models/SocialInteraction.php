<?php

namespace App\Modules\Platform\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\CRM\Models\CRMContact;
use App\Modules\CRM\Models\CRMTask;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single inbound social interaction — a comment or a message — from any
 * connected platform.
 */
class SocialInteraction extends Model
{
    protected $table = 'social_interactions';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'posted_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CRMContact::class, 'crm_contact_id');
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(CRMTask::class, 'crm_task_id');
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'new');
    }
}
