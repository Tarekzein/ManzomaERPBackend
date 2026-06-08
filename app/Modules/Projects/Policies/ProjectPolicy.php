<?php

namespace App\Modules\Projects\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Projects\Models\Project;
use App\Modules\Projects\Models\ProjectTask;
use Illuminate\Auth\Access\AuthorizationException;

class ProjectPolicy
{
    public function ensureCanList(User $actor): void
    {
        if (! $actor->isSuperAdmin() && ! $actor->company_id) {
            throw new AuthorizationException('User is not assigned to a company.');
        }
    }

    public function ensureCanViewProject(User $actor, Project $project): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($actor->company_id === $project->company_id) {
            return;
        }

        throw new AuthorizationException('You cannot view this project.');
    }

    public function ensureCanManageProject(User $actor, ?Project $project = null): void
    {
        if ($actor->isSuperAdmin() || $actor->hasAnyRole([UserRole::CompanyAdmin->value, UserRole::Manager->value])) {
            if ($project) {
                $this->ensureCanViewProject($actor, $project);
            }

            return;
        }

        throw new AuthorizationException('You cannot manage projects.');
    }

    public function ensureCanWorkOnTask(User $actor, ProjectTask $task): void
    {
        $task->loadMissing('project');
        $this->ensureCanViewProject($actor, $task->project);

        if ($actor->isSuperAdmin() || $this->canManageCompanyWork($actor)) {
            return;
        }

        if ($task->assignee_id === $actor->id || $task->project->owner_id === $actor->id) {
            return;
        }

        throw new AuthorizationException('You cannot update this task activity.');
    }

    public function resolveCompanyId(User $actor, ?int $companyId): int
    {
        if ($actor->isSuperAdmin()) {
            if (! $companyId) {
                throw new AuthorizationException('company_id is required for super admin project creation.');
            }

            return $companyId;
        }

        if (! $actor->company_id) {
            throw new AuthorizationException('User is not assigned to a company.');
        }

        return $actor->company_id;
    }

    public function ensureUserBelongsToCompany(User $user, int $companyId): void
    {
        if ($user->company_id === $companyId) {
            return;
        }

        throw new AuthorizationException('Selected user does not belong to this company.');
    }

    private function canManageCompanyWork(User $actor): bool
    {
        return $actor->hasAnyRole([UserRole::CompanyAdmin->value, UserRole::Manager->value]);
    }
}
