<?php

namespace App\Modules\HR\Contracts;

use Illuminate\Database\Eloquent\Model;

interface HRRepository
{
    public function list(string $model, int $companyId, array $with = []);

    public function create(string $model, array $data): Model;

    public function update(Model $model, array $data): Model;
}
