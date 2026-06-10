<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class ProjectAttachmentResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'disk' => $this->disk,
            'path' => $this->path,
            'original_name' => $this->original_name,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'comment' => $this->comment,
            'uploaded_by' => $this->user('uploader'),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
