<?php

namespace App\Modules\Projects\Contracts;

use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface ProjectTaskRepository
{
    public function paginate(Project $project, int $perPage): LengthAwarePaginator;

    public function create(array $attributes): ProjectTask;

    public function save(ProjectTask $task, array $attributes): ProjectTask;

    public function delete(ProjectTask $task): void;

    public function withDetails(ProjectTask $task): ProjectTask;
}
