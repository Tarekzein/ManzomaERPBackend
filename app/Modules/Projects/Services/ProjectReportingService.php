<?php

namespace App\Modules\Projects\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Policies\ProjectPolicy;
use Illuminate\Support\Collection;

class ProjectReportingService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectPolicy $policy,
    ) {}

    public function gantt(User $actor): Collection
    {
        $this->policy->ensureCanList($actor);

        return $this->projects
            ->timeline($actor->isSuperAdmin() ? null : $actor->company_id)
            ->map(fn (Project $project) => [
                'id' => "project-{$project->id}",
                'type' => 'project',
                'name' => $project->name,
                'start' => $project->start_date?->toDateString(),
                'end' => $project->end_date?->toDateString(),
                'progress' => $this->progressPercent($project),
                'children' => $project->tasks->map(fn ($task) => [
                    'id' => "task-{$task->id}",
                    'type' => 'task',
                    'project_id' => $project->id,
                    'name' => $task->title,
                    'start' => $task->start_date?->toDateString(),
                    'end' => $task->due_date?->toDateString(),
                    'status' => $task->status?->value,
                    'priority' => $task->priority?->value,
                    'assignee' => $task->assignee?->only(['id', 'name', 'email']),
                ])->values(),
            ])->values();
    }

    public function summary(User $actor, Project $project): array
    {
        $this->policy->ensureCanViewProject($actor, $project);

        $project->load(['tasks', 'timeLogs', 'expenses']);

        $estimatedHours = (float) $project->tasks->sum('estimated_hours');
        $actualHours = (float) $project->timeLogs->sum('hours');
        $budget = (float) $project->budget;
        $spent = (float) $project->expenses->sum('amount');

        return [
            'project_id' => $project->id,
            'progress_percent' => $this->progressPercent($project),
            'tasks' => [
                'total' => $project->tasks->count(),
                'done' => $project->tasks->where('status', TaskStatus::Done)->count(),
                'in_progress' => $project->tasks->where('status', TaskStatus::InProgress)->count(),
                'to_do' => $project->tasks->where('status', TaskStatus::ToDo)->count(),
            ],
            'time' => [
                'estimated_hours' => $estimatedHours,
                'actual_hours' => $actualHours,
                'variance_hours' => $estimatedHours - $actualHours,
            ],
            'budget' => [
                'allocated' => $budget,
                'spent' => $spent,
                'variance' => $budget - $spent,
            ],
        ];
    }

    private function progressPercent(Project $project): int
    {
        $total = $project->tasks->count();

        if ($total === 0) {
            return 0;
        }

        return (int) round(($project->tasks->where('status', TaskStatus::Done)->count() / $total) * 100);
    }
}
