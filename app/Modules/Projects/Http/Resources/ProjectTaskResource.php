<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class ProjectTaskResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        $actualHours = $this->floatSum('actual_hours', 'timeLogs', 'hours');
        $estimatedHours = (float) $this->estimated_hours;

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'title' => $this->title,
            'description' => $this->description,
            'priority' => $this->priority?->value,
            'status' => $this->status?->value,
            'estimated_hours' => $estimatedHours,
            'actual_hours' => $actualHours,
            'hours_variance' => $estimatedHours - $actualHours,
            'sort_order' => $this->sort_order,
            'start_date' => $this->date($this->start_date),
            'due_date' => $this->date($this->due_date),
            'completed_at' => $this->dateTime($this->completed_at),
            'assignee' => $this->user('assignee'),
            'time_logs' => $this->loadedCollection(ProjectTimeLogResource::class, 'timeLogs'),
            'attachments' => $this->loadedCollection(ProjectAttachmentResource::class, 'attachments'),
            'comments' => $this->loadedCollection(ProjectCommentResource::class, 'comments'),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
