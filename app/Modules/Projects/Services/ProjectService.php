<?php

namespace App\Modules\Projects\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Contracts\ProjectActivityRepository;
use App\Modules\Projects\Contracts\ProjectRepository;
use App\Modules\Projects\Http\Requests\StoreProjectExpenseRequest;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectExpense;
use App\Modules\Projects\Models\ProjectTask;
use App\Modules\Projects\Policies\ProjectPolicy;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class ProjectService
{
    public function __construct(
        private readonly ProjectRepository $projects,
        private readonly ProjectActivityRepository $activity,
        private readonly ProjectPolicy $policy,
    ) {}

    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        $this->policy->ensureCanList($actor);

        return $this->projects->paginate($actor->isSuperAdmin() ? null : $actor->company_id, $perPage);
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
            $companyId = $this->policy->resolveCompanyId($actor, $data['company_id'] ?? null);
            $owner = User::query()->findOrFail($data['owner_id'] ?? $actor->id);

            $this->policy->ensureUserBelongsToCompany($owner, $companyId);

            $project = $this->projects->create([
                'company_id' => $companyId,
                'owner_id' => $owner->id,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'start_date' => $data['start_date'] ?? null,
                'end_date' => $data['end_date'] ?? null,
                'budget' => $data['budget'] ?? 0,
                'status' => $data['status'] ?? 'active',
            ]);

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

    public function recordExpense(User $actor, Project $project, StoreProjectExpenseRequest $request): ProjectExpense
    {
        $this->policy->ensureCanManageProject($actor, $project);
        $data = $request->validated();

        if (! empty($data['task_id'])) {
            $task = ProjectTask::query()->findOrFail($data['task_id']);
            $this->ensureTaskBelongsToProject($task, $project);
        }

        return $this->activity->expense([
            'project_id' => $project->id,
            'task_id' => $data['task_id'] ?? null,
            'finance_reference' => $data['finance_reference'] ?? null,
            'category' => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'amount' => $data['amount'],
            'expense_date' => $data['expense_date'],
        ]);
    }

    private function ensureTaskBelongsToProject(ProjectTask $task, Project $project): void
    {
        abort_unless($task->project_id === $project->id, 422, 'Task does not belong to this project.');
    }
}
