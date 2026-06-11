<?php

namespace App\Modules\Projects\Http\Requests;

class StoreProjectExpenseRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'task_id' => ['nullable', 'integer', 'exists:project_tasks,id'],
            'finance_reference' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'expense_date' => ['required', 'date'],
        ];
    }
}
