<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class ProjectExpenseResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'task_id' => $this->task_id,
            'finance_reference' => $this->finance_reference,
            'category' => $this->category,
            'description' => $this->description,
            'amount' => (float) $this->amount,
            'expense_date' => $this->date($this->expense_date),
            'created_at' => $this->dateTime($this->created_at),
        ];
    }
}
