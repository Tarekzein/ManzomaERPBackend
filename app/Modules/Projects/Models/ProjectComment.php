<?php

namespace App\Modules\Projects\Models;

use App\Modules\Authentication\Models\User;
use Database\Factories\ProjectCommentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectComment extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'task_id', 'user_id', 'body'];

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

    protected static function newFactory(): ProjectCommentFactory
    {
        return ProjectCommentFactory::new();
    }
}
