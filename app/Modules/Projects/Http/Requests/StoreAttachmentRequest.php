<?php

namespace App\Modules\Projects\Http\Requests;

class StoreAttachmentRequest extends ProjectFormRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:20480'],
            'comment' => ['nullable', 'string'],
        ];
    }
}
