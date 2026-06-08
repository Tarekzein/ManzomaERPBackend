<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentProjectRepository implements ProjectRepository
{
    public function paginate(?int $companyId, int $perPage): LengthAwarePaginator
    {
        return Project::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->with(['owner:id,name,email'])
            ->withCount('tasks')
            ->withSum('timeLogs as actual_hours', 'hours')
            ->withSum('expenses as actual_expenses', 'amount')
            ->latest()
            ->paginate($perPage);
    }

    public function create(array $attributes): Project
    {
        return Project::query()->create($attributes);
    }

    public function save(Project $project, array $attributes): Project
    {
        $project->fill($attributes)->save();

        return $project->refresh();
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function withDetails(Project $project): Project
    {
        return $project->load([
            'owner:id,name,email',
            'tasks.assignee:id,name,email',
            'timeLogs.user:id,name,email',
            'attachments.uploader:id,name,email',
            'comments.user:id,name,email',
            'expenses',
        ])->loadSum('timeLogs as actual_hours', 'hours')
            ->loadSum('expenses as actual_expenses', 'amount');
    }

    public function timeline(?int $companyId): Collection
    {
        return Project::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->with(['tasks.assignee:id,name,email'])
            ->orderBy('start_date')
            ->get();
    }
}
