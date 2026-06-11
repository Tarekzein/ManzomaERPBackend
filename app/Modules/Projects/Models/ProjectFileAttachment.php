<?php

namespace App\Modules\Projects\Models;

use App\Modules\Authentication\Models\User;
use Database\Factories\ProjectFileAttachmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectFileAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'task_id',
        'uploaded_by',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size',
        'comment',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'task_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected static function newFactory(): ProjectFileAttachmentFactory
    {
        return ProjectFileAttachmentFactory::new();
    }
}
