<?php

namespace App\Modules\Projects\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ProjectFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
