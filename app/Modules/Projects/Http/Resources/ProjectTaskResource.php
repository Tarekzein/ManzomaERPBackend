<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTaskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actualHours = (float) ($this->actual_hours ?? $this->timeLogs->sum('hours'));
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
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toISOString(),
            'assignee' => UserSummaryResource::make($this->whenLoaded('assignee')),
            'time_logs' => ProjectTimeLogResource::collection($this->whenLoaded('timeLogs')),
            'attachments' => ProjectAttachmentResource::collection($this->whenLoaded('attachments')),
            'comments' => ProjectCommentResource::collection($this->whenLoaded('comments')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
