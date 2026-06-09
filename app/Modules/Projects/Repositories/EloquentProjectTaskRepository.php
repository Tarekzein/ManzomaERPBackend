<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectTaskRepository;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProjectTaskRepository implements ProjectTaskRepository
{
    public function paginate(Project $project, int $perPage, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        return $project->tasks()
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['assignee_id'] ?? null, fn ($query, $assigneeId) => $query->where('assignee_id', $assigneeId))
            ->when($filters['starts_from'] ?? null, fn ($query, $date) => $query->whereDate('start_date', '>=', $date))
            ->when($filters['starts_before'] ?? null, fn ($query, $date) => $query->whereDate('start_date', '<=', $date))
            ->when($filters['due_from'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '>=', $date))
            ->when($filters['due_before'] ?? null, fn ($query, $date) => $query->whereDate('due_date', '<=', $date))
            ->when($filters['search'] ?? null, function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
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

    private function applySort($query, ?string $sort): void
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
}
