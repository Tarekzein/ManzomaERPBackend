<?php

namespace App\Modules\Projects\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Contracts\ProjectActivityRepository;
use App\Modules\Projects\Contracts\ProjectTaskRepository;
use App\Modules\Projects\Enums\TaskStatus;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Models\ProjectTimeLog;
use App\Modules\Projects\Policies\ProjectPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ProjectTaskService
{
    public function __construct(
        private readonly ProjectTaskRepository $tasks,
        private readonly ProjectActivityRepository $activity,
        private readonly ProjectPolicy $policy,
    ) {}

    public function list(User $actor, Project $project, int $perPage): LengthAwarePaginator
    {
        $this->policy->ensureCanViewProject($actor, $project);

        return $this->tasks->paginate($project, $perPage);
    }

    public function create(User $actor, Project $project, array $data): ProjectTask
    {
        $this->policy->ensureCanManageProject($actor, $project);

        return DB::transaction(function () use ($project, $data) {
            $this->ensureAssigneeBelongsToProjectCompany($data['assignee_id'] ?? null, $project);

            $task = $this->tasks->create([
                'project_id' => $project->id,
                'assignee_id' => $data['assignee_id'] ?? null,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'priority' => $data['priority'] ?? 'medium',
                'status' => $data['status'] ?? TaskStatus::ToDo->value,
                'estimated_hours' => $data['estimated_hours'] ?? 0,
                'sort_order' => $data['sort_order'] ?? 0,
                'start_date' => $data['start_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
            ]);

            return $this->tasks->withDetails($task);
        });
    }

    public function show(User $actor, ProjectTask $task): ProjectTask
    {
        $this->policy->ensureCanViewProject($actor, $task->project);

        return $this->tasks->withDetails($task);
    }

    public function update(User $actor, ProjectTask $task, array $data): ProjectTask
    {
        $this->policy->ensureCanManageProject($actor, $task->project);

        return DB::transaction(function () use ($task, $data) {
            $this->ensureAssigneeBelongsToProjectCompany($data['assignee_id'] ?? null, $task->project);

            if (($data['status'] ?? null) === TaskStatus::Done->value && ! $task->completed_at) {
                $data['completed_at'] = now();
            }

            if (($data['status'] ?? null) && $data['status'] !== TaskStatus::Done->value) {
                $data['completed_at'] = null;
            }

            return $this->tasks->withDetails($this->tasks->save($task, $data));
        });
    }

    public function delete(User $actor, ProjectTask $task): void
    {
        $this->policy->ensureCanManageProject($actor, $task->project);
        $this->tasks->delete($task);
    }

    public function logTime(User $actor, ProjectTask $task, array $data): ProjectTimeLog
    {
        $this->policy->ensureCanWorkOnTask($actor, $task);

        return $this->activity->logTime([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'work_date' => $data['work_date'],
            'hours' => $data['hours'],
            'notes' => $data['notes'] ?? null,
        ])->load('user:id,name,email');
    }

    public function attachFile(User $actor, ProjectTask $task, UploadedFile $file, ?string $comment): ProjectFileAttachment
    {
        $this->policy->ensureCanWorkOnTask($actor, $task);

        $disk = config('projects.filesystem_disk', 's3');
        $path = $file->store("projects/{$task->project_id}/tasks/{$task->id}", $disk);

        abort_unless($path, 500, 'File could not be stored.');

        return $this->activity->attachFile([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'uploaded_by' => $actor->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'comment' => $comment,
        ])->load('uploader:id,name,email');
    }

    public function comment(User $actor, ProjectTask $task, string $body): ProjectComment
    {
        $this->policy->ensureCanWorkOnTask($actor, $task);

        return $this->activity->comment([
            'project_id' => $task->project_id,
            'task_id' => $task->id,
            'user_id' => $actor->id,
            'body' => $body,
        ])->load('user:id,name,email');
    }

    private function ensureAssigneeBelongsToProjectCompany(?int $assigneeId, Project $project): void
    {
        if (! $assigneeId) {
            return;
        }

        $assignee = User::query()->findOrFail($assigneeId);
        $this->policy->ensureUserBelongsToCompany($assignee, $project->company_id);
    }
}
