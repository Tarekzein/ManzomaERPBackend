<?php

namespace App\Modules\Projects\Repositories;

use App\Modules\Projects\Contracts\ProjectTaskRepository;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentProjectTaskRepository implements ProjectTaskRepository
{
    public function paginate(Project $project, int $perPage): LengthAwarePaginator
    {
        return $project->tasks()
            ->with(['assignee:id,name,email'])
            ->withSum('timeLogs as actual_hours', 'hours')
            ->orderBy('sort_order')
            ->latest('id')
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
}
