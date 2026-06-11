<?php

namespace App\Modules\Projects\Http\Resources;

use Illuminate\Http\Request;

class UserSummaryResource extends ProjectJsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
        ];
    }
}
