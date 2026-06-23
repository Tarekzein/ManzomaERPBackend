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
            ->timeline($actor->isSuperAdmin() ? null : $actor->company_id, $actor)
            ->map(fn (Project $project) => [
                'id' => "project-{$project->id}",
                'type' => 'project',
                'name' => $project->name,
                'start' => $project->start_date?->toDateString(),
                'end' => $project->end_date?->toDateString(),
                'progress' => $this->progressPercent($project),
                'children' => $this->taskTimeline($project),
            ])->values();
    }

    public function summary(User $actor, Project $project): array
    {
        $this->policy->ensureCanViewProject($actor, $project);

        $project->load(['tasks', 'timeLogs', 'expenses']);

        $time = $this->timeMetrics($project);
        $budget = $this->budgetMetrics($project);

        return [
            'project_id' => $project->id,
            'progress_percent' => $this->progressPercent($project),
            'tasks' => $this->taskCounts($project),
            'time' => $time,
            'budget' => $budget,
            'estimated_hours' => $time['estimated_hours'],
            'actual_hours' => $time['actual_hours'],
            'variance_hours' => $time['variance_hours'],
            'budget_allocated' => $budget['budget_allocated'],
            'budget_spent' => $budget['budget_spent'],
            'budget_variance' => $budget['budget_variance'],
        ];
    }

    private function taskTimeline(Project $project): Collection
    {
        return $project->tasks->map(fn ($task) => [
            'id' => "task-{$task->id}",
            'type' => 'task',
            'project_id' => $project->id,
            'name' => $task->title,
            'start' => $task->start_date?->toDateString(),
            'end' => $task->due_date?->toDateString(),
            'status' => $task->status?->value,
            'priority' => $task->priority?->value,
            'assignee' => $task->assignee?->only(['id', 'name', 'email']),
        ])->values();
    }

    private function taskCounts(Project $project): array
    {
        return [
            'total' => $project->tasks->count(),
            'done' => $project->tasks->where('status', TaskStatus::Done)->count(),
            'in_progress' => $project->tasks->where('status', TaskStatus::InProgress)->count(),
            'to_do' => $project->tasks->where('status', TaskStatus::ToDo)->count(),
        ];
    }

    private function timeMetrics(Project $project): array
    {
        $estimated = (float) $project->tasks->sum('estimated_hours');
        $actual = (float) $project->timeLogs->sum('hours');

        return [
            'estimated_hours' => $estimated,
            'actual_hours' => $actual,
            'variance_hours' => $estimated - $actual,
        ];
    }

    private function budgetMetrics(Project $project): array
    {
        $allocated = (float) $project->budget;
        $spent = (float) $project->expenses->sum('amount');
        $variance = $allocated - $spent;

        return [
            'allocated' => $allocated,
            'spent' => $spent,
            'variance' => $variance,
            'budget_allocated' => $allocated,
            'budget_spent' => $spent,
            'budget_variance' => $variance,
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
