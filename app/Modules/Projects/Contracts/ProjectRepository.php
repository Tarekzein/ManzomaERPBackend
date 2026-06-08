<?php

namespace App\Modules\Projects\Contracts;

use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProjectRepository
{
    public function paginate(?int $companyId, int $perPage): LengthAwarePaginator;

    public function create(array $attributes): Project;

    public function save(Project $project, array $attributes): Project;

    public function delete(Project $project): void;

    public function withDetails(Project $project): Project;

    public function timeline(?int $companyId): Collection;
}
