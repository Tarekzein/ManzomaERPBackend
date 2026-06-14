<?php

namespace App\Modules\HR\Repositories;

use App\Modules\HR\Contracts\HRRepository;
use Illuminate\Database\Eloquent\Model;

class EloquentHRRepository implements HRRepository
{
    public function list(string $model, int $companyId, array $with = [])
    {
        return $model::with($with)->where('company_id', $companyId)->latest('id')->get();
    }

    public function create(string $model, array $data): Model
    {
        return $model::create($data);
    }

    public function update(Model $model, array $data): Model
    {
        $model->update($data);

        return $model->refresh();
    }
}
