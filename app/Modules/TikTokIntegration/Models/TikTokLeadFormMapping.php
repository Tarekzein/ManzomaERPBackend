<?php

namespace App\Modules\TikTokIntegration\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TikTokLeadFormMapping extends Model
{
    protected $table = 'tiktok_lead_form_mappings';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'field_mapping' => 'array',
            'is_active' => 'boolean',
            'task_requested_at' => 'datetime',
            'synced_through' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(TikTokConnection::class, 'tiktok_connection_id');
    }

    public function defaultOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_owner_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function hasPendingTask(): bool
    {
        return filled($this->current_task_id) && $this->task_status !== 'SUCCESS';
    }

    /** A task that never completed should not block the next run forever. */
    public function taskHasStalled(int $minutes = 60): bool
    {
        return $this->hasPendingTask()
            && $this->task_requested_at !== null
            && $this->task_requested_at->isBefore(now()->subMinutes($minutes));
    }
}
