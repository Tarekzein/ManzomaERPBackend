<?php

namespace App\Modules\Projects\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Contracts\ProjectActivityRepository;
use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectComment;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectFileAttachment;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Policies\ProjectPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectActivityRepository $activity,
        private readonly ProjectPolicy $policy,
    ) {}

    public function list(User $actor, int $perPage, array $filters = [], ?string $sort = null): LengthAwarePaginator
    {
        $this->policy->ensureCanList($actor);

        return $this->projects->paginate(
            $actor->isSuperAdmin() ? null : $actor->company_id,
            $perPage,
            $filters,
            $sort
        );
    }

    public function show(User $actor, Project $project): Project
    {
        $this->policy->ensureCanViewProject($actor, $project);

        return $this->projects->withDetails($project);
    }

    public function create(User $actor, array $data): Project
    {
        $this->policy->ensureCanManageProject($actor);

        return DB::transaction(function () use ($actor, $data) {
            $companyId = $this->companyIdFor($actor, $data);
            $owner = $this->ownerFor($actor, $data, $companyId);
            $project = $this->projects->create($this->projectData($data, $companyId, $owner));

            return $this->projects->withDetails($project);
        });
    }

    public function update(User $actor, Project $project, array $data): Project
    {
        $this->policy->ensureCanManageProject($actor, $project);

        return DB::transaction(function () use ($project, $data) {
            if (array_key_exists('owner_id', $data)) {
                $owner = User::query()->findOrFail($data['owner_id']);
                $this->policy->ensureUserBelongsToCompany($owner, $project->company_id);
            }

            return $this->projects->withDetails($this->projects->save($project, $data));
        });
    }

    public function delete(User $actor, Project $project): void
    {
        $this->policy->ensureCanManageProject($actor, $project);
        $this->projects->delete($project);
    }

    public function recordExpense(User $actor, Project $project, array $data): ProjectExpense
    {
        $this->policy->ensureCanManageProject($actor, $project);

        if (! empty($data['task_id'])) {
            $task = ProjectTask::query()->findOrFail($data['task_id']);
            $this->ensureTaskBelongsToProject($task, $project);
        }

        return $this->activity->expense($this->expenseData($project, $data));
    }

    public function attachFile(User $actor, Project $project, UploadedFile $file, ?string $comment): ProjectFileAttachment
    {
        $this->policy->ensureCanContributeToProject($actor, $project);

        $disk = config('projects.filesystem_disk', 's3');
        $path = $file->store("projects/{$project->id}", $disk);

        abort_unless($path, 500, 'File could not be stored.');

        return $this->activity
            ->attachFile($this->attachmentData($actor, $project, $file, $disk, $path, $comment))
            ->load('uploader:id,name,email');
    }

    public function comment(User $actor, Project $project, string $body): ProjectComment
    {
        $this->policy->ensureCanContributeToProject($actor, $project);

        return $this->activity->comment([
            'project_id' => $project->id,
            'task_id' => null,
            'user_id' => $actor->id,
            'body' => $body,
        ])->load('user:id,name,email');
    }

    private function companyIdFor(User $actor, array $data): int
    {
        return $this->policy->resolveCompanyId($actor, $data['company_id'] ?? null);
    }

    private function ownerFor(User $actor, array $data, int $companyId): User
    {
        $owner = User::query()->findOrFail($data['owner_id'] ?? $actor->id);
        $this->policy->ensureUserBelongsToCompany($owner, $companyId);

        return $owner;
    }

    private function projectData(array $data, int $companyId, User $owner): array
    {
        return [
            'company_id' => $companyId,
            'owner_id' => $owner->id,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'start_date' => $data['start_date'] ?? null,
            'end_date' => $data['end_date'] ?? null,
            'budget' => $data['budget'] ?? 0,
            'status' => $data['status'] ?? 'active',
        ];
    }

    private function expenseData(Project $project, array $data): array
    {
        return [
            'project_id' => $project->id,
            'task_id' => $data['task_id'] ?? null,
            'finance_reference' => $data['finance_reference'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
        ];
    }

    private function attachmentData(
        User $actor,
        Project $project,
        UploadedFile $file,
        string $disk,
        string $path,
        ?string $comment
    ): array {
        return [
            'project_id' => $project->id,
            'task_id' => null,
            'uploaded_by' => $actor->id,
            'disk' => $disk,
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize() ?: 0,
            'comment' => $comment,
        ];
    }

    private function ensureTaskBelongsToProject(ProjectTask $task, Project $project): void
    {
        abort_unless($task->project_id === $project->id, 422, 'Task does not belong to this project.');
    }
}
