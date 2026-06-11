<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use Illuminate\Validation\Rule;

class UpdateProjectTaskRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'assignee_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', Rule::in(TaskPriority::values())],
            'status' => ['sometimes', Rule::in(TaskStatus::values())],
            'estimated_hours' => ['sometimes', 'numeric', 'min:0'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'due_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
