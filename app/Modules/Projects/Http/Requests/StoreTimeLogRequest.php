<?php

namespace App\Modules\Projects\Http\Requests;

class StoreTimeLogRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
