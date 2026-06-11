<?php

namespace App\Modules\Projects\Http\Requests;

class StoreCommentRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
        ];
    }
}
