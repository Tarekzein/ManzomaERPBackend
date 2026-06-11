<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class ProjectTimeLogResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'user' => $this->user('user'),
            'work_date' => $this->date($this->work_date),
            'hours' => (float) $this->hours,
            'notes' => $this->notes,
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
