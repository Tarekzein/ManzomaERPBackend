<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actualHours = (float) ($this->actual_hours ?? $this->timeLogs->sum('hours'));
        $actualExpenses = (float) ($this->actual_expenses ?? $this->expenses->sum('amount'));
        $budget = (float) $this->budget;

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status?->value,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'budget' => $budget,
            'actual_hours' => $actualHours,
            'actual_expenses' => $actualExpenses,
            'budget_variance' => $budget - $actualExpenses,
            'tasks_count' => $this->tasks_count,
            'owner' => UserSummaryResource::make($this->whenLoaded('owner')),
            'tasks' => ProjectTaskResource::collection($this->whenLoaded('tasks')),
            'time_logs' => ProjectTimeLogResource::collection($this->whenLoaded('timeLogs')),
            'attachments' => ProjectAttachmentResource::collection($this->whenLoaded('attachments')),
            'comments' => ProjectCommentResource::collection($this->whenLoaded('comments')),
            'expenses' => ProjectExpenseResource::collection($this->whenLoaded('expenses')),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
