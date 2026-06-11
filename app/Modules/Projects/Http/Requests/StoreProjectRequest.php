<?php

namespace App\Modules\Projects\Http\Requests;

use App\Modules\Projects\Enums\ProjectStatus;
use Illuminate\Validation\Rule;

class StoreProjectRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'owner_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'budget' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', Rule::in(ProjectStatus::values())],
        ];
    }
}
