<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Enums\TaskPriority;
use App\Modules\Projects\Enums\TaskStatus;
use Illuminate\Validation\Rule;

class StoreProjectTaskRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'assignee_id' => ['nullable', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['nullable', Rule::in(TaskPriority::values())],
            'status' => ['nullable', Rule::in(TaskStatus::values())],
            'estimated_hours' => ['nullable', 'numeric', 'min:0'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
