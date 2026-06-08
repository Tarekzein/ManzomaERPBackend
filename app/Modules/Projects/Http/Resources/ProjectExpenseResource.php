<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectExpenseResource extends JsonResource
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
            'expense_date' => $this->expense_date?->toDateString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
