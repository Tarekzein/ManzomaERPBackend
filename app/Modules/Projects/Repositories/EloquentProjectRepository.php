<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\WorkScopeService;
use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Models\Project;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class EloquentProjectRepository implements ProjectRepository
{
    public function __construct(private readonly WorkScopeService $scope) {}

    public function paginate(?int $companyId, int $perPage, array $filters = [], ?string $sort = null, ?User $actor = null): LengthAwarePaginator
    {
        return Project::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($actor, fn ($query) => $this->scope->applyProjectScope($query, $actor))
            ->tap(fn ($query) => $this->applyFilters($query, $filters))
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

    public function timeline(?int $companyId, ?User $actor = null): Collection
    {
        return Project::query()
            ->when($companyId, fn ($query) => $query->where('company_id', $companyId))
            ->when($actor, fn ($query) => $this->scope->applyProjectScope($query, $actor))
            ->with(['tasks.assignee:id,name,email'])
            ->orderBy('start_date')
            ->get();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['status', 'owner_id'] as $field) {
            $query->when($filters[$field] ?? null, fn ($query, $value) => $query->where($field, $value));
        }

        foreach ($this->dateFilters() as $filter => [$column, $operator]) {
            $query->when($filters[$filter] ?? null, fn ($query, $date) => $query->whereDate($column, $operator, $date));
        }

        $query->when($filters['search'] ?? null, fn ($query, $search) => $this->applySearch($query, $search));
    }

    private function applySearch(Builder $query, string $search): void
    {
        $query->where(function (Builder $query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    private function applySort(Builder $query, ?string $sort): void
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

    private function dateFilters(): array
    {
        return [
            'starts_from' => ['start_date', '>='],
            'starts_before' => ['start_date', '<='],
            'ends_from' => ['end_date', '>='],
            'ends_before' => ['end_date', '<='],
        ];
    }
}
