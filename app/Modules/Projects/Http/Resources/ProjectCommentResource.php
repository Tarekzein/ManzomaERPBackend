<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class ProjectCommentResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'body' => $this->body,
            'user' => $this->user('user'),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
