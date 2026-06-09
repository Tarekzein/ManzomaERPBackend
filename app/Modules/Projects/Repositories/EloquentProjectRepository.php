<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

class EloquentProjectRepository implements ProjectRepository
{
    public function paginate(?int $companyId, int $perPage, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        return Project::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['owner_id'] ?? null, fn ($query, $ownerId) => $query->where('owner_id', $ownerId))
            ->when($filters['starts_from'] ?? null, fn ($query, $date) => $query->whereDate('start_date', '>=', $date))
            ->when($filters['starts_before'] ?? null, fn ($query, $date) => $query->whereDate('start_date', '<=', $date))
            ->when($filters['ends_from'] ?? null, fn ($query, $date) => $query->whereDate('end_date', '>=', $date))
            ->when($filters['ends_before'] ?? null, fn ($query, $date) => $query->whereDate('end_date', '<=', $date))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->with(['owner:id,name,email'])
            ->withCount('tasks')
            ->withSum('timeLogs as actual_hours', 'hours')
            ->withSum('expenses as actual_expenses', 'amount')
            ->tap(fn ($query) => $this->applySort($query, $sort))
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

    private function applySort($query, ?string $sort): void
    {
        $allowed = [
            'name',
            'status',
            'start_date',
            'end_date',
            'budget',
            'created_at',
            'updated_at',
        ];

        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        if (! in_array($column, $allowed, true)) {
            $query->latest();

            return;
        }

        $query->orderBy($column, $direction);
    }
}
