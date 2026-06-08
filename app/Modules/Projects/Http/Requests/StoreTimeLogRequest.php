<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTimeLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'work_date' => ['required', 'date'],
            'hours' => ['required', 'numeric', 'min:0.01', 'max:24'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
