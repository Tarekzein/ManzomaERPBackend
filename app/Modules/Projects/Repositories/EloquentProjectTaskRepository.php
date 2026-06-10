<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectTaskRepository;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class EloquentProjectTaskRepository implements ProjectTaskRepository
{
    public function paginate(Project $project, int $perPage, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        return $project->tasks()
            ->tap(fn ($query) => $this->applyFilters($query, $filters))
            ->with(['assignee:id,name,email'])
            ->withSum('timeLogs as actual_hours', 'hours')
            ->tap(fn ($query) => $this->applySort($query, $sort))
            ->paginate($perPage);
    }

    public function create(array $attributes): ProjectTask
    {
        return ProjectTask::query()->create($attributes);
    }

    public function save(ProjectTask $task, array $attributes): ProjectTask
    {
        $task->fill($attributes)->save();

        return $task->refresh();
    }

    public function delete(ProjectTask $task): void
    {
        $task->delete();
    }

    public function withDetails(ProjectTask $task): ProjectTask
    {
        return $task->load([
            'project.owner:id,name,email',
            'assignee:id,name,email',
            'timeLogs.user:id,name,email',
            'attachments.uploader:id,name,email',
            'comments.user:id,name,email',
        ])->loadSum('timeLogs as actual_hours', 'hours');
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['status', 'priority', 'assignee_id'] as $field) {
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
            $query->where('title', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        $allowed = [
            'title',
            'priority',
            'status',
            'estimated_hours',
            'sort_order',
            'start_date',
            'due_date',
            'created_at',
            'updated_at',
        ];

        $direction = str_starts_with((string) $sort, '-') ? 'desc' : 'asc';
        $column = ltrim((string) $sort, '-');

        if (! in_array($column, $allowed, true)) {
            $query->orderBy('sort_order')->latest('id');

            return;
        }

        $query->orderBy($column, $direction);
    }

    private function dateFilters(): array
    {
        return [
            'starts_from' => ['start_date', '>='],
            'starts_before' => ['start_date', '<='],
            'due_from' => ['due_date', '>='],
            'due_before' => ['due_date', '<='],
        ];
    }
}
