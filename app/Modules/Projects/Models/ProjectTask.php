<?php

namespace App\Modules\Projects\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectTask extends Model
{
    protected $fillable = [
        'project_id',
        'assignee_id',
        'title',
        'description',
        'priority',
        'status',
        'estimated_hours',
        'sort_order',
        'start_date',
        'due_date',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'priority' => TaskPriority::class,
            'status' => TaskStatus::class,
            'estimated_hours' => 'decimal:2',
            'start_date' => 'date',
            'due_date' => 'date',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(ProjectTimeLog::class, 'task_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ProjectFileAttachment::class, 'task_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class, 'task_id');
    }
}
