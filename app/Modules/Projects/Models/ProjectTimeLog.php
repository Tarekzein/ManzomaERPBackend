<?php

namespace App\Modules\Projects\Models;

use App\Modules\Authentication\Models\User;
use Database\Factories\ProjectTimeLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectTimeLog extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'task_id', 'user_id', 'work_date', 'hours', 'notes'];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'hours' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function newFactory(): ProjectTimeLogFactory
    {
        return ProjectTimeLogFactory::new();
    }
}
