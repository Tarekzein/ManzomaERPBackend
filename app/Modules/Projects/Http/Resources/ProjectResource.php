<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class ProjectResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        $actualHours = $this->floatSum('actual_hours', 'timeLogs', 'hours');
        $actualExpenses = $this->floatSum('actual_expenses', 'expenses', 'amount');
        $budget = (float) $this->budget;

        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status?->value,
            'start_date' => $this->date($this->start_date),
            'end_date' => $this->date($this->end_date),
            'budget' => $budget,
            'actual_hours' => $actualHours,
            'actual_expenses' => $actualExpenses,
            'budget_variance' => $budget - $actualExpenses,
            'tasks_count' => $this->tasks_count,
            'owner' => $this->user('owner'),
            'tasks' => $this->loadedCollection(ProjectTaskResource::class, 'tasks'),
            'time_logs' => $this->loadedCollection(ProjectTimeLogResource::class, 'timeLogs'),
            'attachments' => $this->loadedCollection(ProjectAttachmentResource::class, 'attachments'),
            'comments' => $this->loadedCollection(ProjectCommentResource::class, 'comments'),
            'expenses' => $this->loadedCollection(ProjectExpenseResource::class, 'expenses'),
            'created_at' => $this->dateTime($this->created_at),
            'updated_at' => $this->dateTime($this->updated_at),
        ];
    }
}
