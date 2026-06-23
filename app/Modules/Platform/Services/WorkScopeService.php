<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Projects\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class WorkScopeService
{
    public function isCompanyWide(User $user): bool
    {
        return $user->isSuperAdmin() || $user->hasRole(UserRole::CompanyAdmin->value);
    }

    public function isManager(User $user): bool
    {
        return $user->hasRole(UserRole::Manager->value);
    }

    public function employee(User $user): ?Employee
    {
        if (! $user->company_id) {
            return null;
        }

        return Employee::query()
            ->where('company_id', $user->company_id)
            ->where('user_id', $user->id)
            ->first();
    }

    public function directReportEmployeeIds(User $user): array
    {
        $employee = $this->employee($user);

        if (! $employee) {
            return [];
        }

        return Employee::query()
            ->where('company_id', $employee->company_id)
            ->where('manager_id', $employee->id)
            ->pluck('id')
            ->all();
    }

    public function directReportUserIds(User $user): array
    {
        $reportIds = $this->directReportEmployeeIds($user);

        if ($reportIds === []) {
            return [];
        }

        return Employee::query()
            ->whereIn('id', $reportIds)
            ->whereNotNull('user_id')
            ->pluck('user_id')
            ->all();
    }

    public function scopedUserIds(User $user): array
    {
        if ($this->isCompanyWide($user)) {
            return [];
        }

        return array_values(array_unique([
            $user->id,
            ...($this->isManager($user) ? $this->directReportUserIds($user) : []),
        ]));
    }

    public function scopedEmployeeIds(User $user): array
    {
        if ($this->isCompanyWide($user)) {
            return [];
        }

        $employee = $this->employee($user);
        $ids = $employee ? [$employee->id] : [];

        if ($this->isManager($user)) {
            $ids = [...$ids, ...$this->directReportEmployeeIds($user)];
        }

        return array_values(array_unique($ids));
    }

    public function canViewProject(User $user, Project $project): bool
    {
        if ($this->isCompanyWide($user)) {
            return $user->isSuperAdmin() || $user->company_id === $project->company_id;
        }

        if ($user->company_id !== $project->company_id) {
            return false;
        }

        $userIds = $this->scopedUserIds($user);

        return $project->owner_id === $user->id
            || $project->tasks()->whereIn('assignee_id', $userIds)->exists();
    }

    public function applyProjectScope(Builder $query, User $user): Builder
    {
        if ($this->isCompanyWide($user)) {
            return $query;
        }

        $userIds = $this->scopedUserIds($user);

        return $query->where(function (Builder $query) use ($user, $userIds) {
            $query->where('owner_id', $user->id)
                ->orWhereHas('tasks', fn (Builder $tasks) => $tasks->whereIn('assignee_id', $userIds));
        });
    }

    public function directReports(User $user): Collection
    {
        $ids = $this->directReportEmployeeIds($user);

        return $ids === []
            ? collect()
            : Employee::query()->whereIn('id', $ids)->with('user:id,name,email')->get();
    }
}
