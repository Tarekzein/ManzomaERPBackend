<?php

namespace App\Modules\Projects\Contracts;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface ProjectRepository
{
    public function paginate(?int $companyId, int $perPage, array $filters = [], ?string $sort = null, ?User $actor = null): LengthAwarePaginator;

    public function create(array $attributes): Project;

    public function save(Project $project, array $attributes): Project;

    public function delete(Project $project): void;

    public function withDetails(Project $project): Project;

    public function timeline(?int $companyId, ?User $actor = null): Collection;
}
