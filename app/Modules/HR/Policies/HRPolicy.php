<?php

namespace App\Modules\HR\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\WorkScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class HRPolicy
{
    public function __construct(
        private readonly WorkScopeService $scope,
        private readonly CompanyContext $context,
    ) {}

    public function companyId(User $user, string $permission = 'hr.view'): int
    {
        $companyId = $this->context->companyIdFor($user);

        if (! $companyId || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this HR operation.');
        }

        return $companyId;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'hr.edit'): int
    {
        $id = $this->companyId($user, $permission);
        if ((int) $model->getAttribute('company_id') !== $id) {
            throw new AuthorizationException('This HR record belongs to another company.');
        }

        return $id;
    }

    public function employee(User $user): Employee
    {
        $employee = Employee::where('company_id', $this->companyId($user))->where('user_id', $user->id)->first();

        if (! $employee) {
            throw ValidationException::withMessages([
                'employee' => ['Your user account is not linked to an employee profile yet. Ask your company admin to complete your HR profile.'],
            ]);
        }

        return $employee;
    }

    public function scopedEmployeeIds(User $user): array
    {
        if ($this->context->hasCompanyRole($user, UserRole::CompanyAdmin->value) || $user->isSuperAdmin()) {
            return [];
        }

        return $this->scope->scopedEmployeeIds($user);
    }

    public function hasCompanyWideScope(User $user): bool
    {
        return $this->scope->isCompanyWide($user);
    }

    public function canViewEmployee(User $user, Employee $employee): bool
    {
        if ($user->isSuperAdmin() || $this->context->hasCompanyRole($user, UserRole::CompanyAdmin->value)) {
            return $user->isSuperAdmin() || $this->context->companyIdFor($user) === (int) $employee->company_id;
        }

        return in_array($employee->id, $this->scope->scopedEmployeeIds($user), true);
    }

    public function canReview(User $user, Employee $employee): void
    {
        if ($this->context->hasCompanyRole($user, UserRole::CompanyAdmin->value) || $employee->manager?->user_id === $user->id) {
            return;
        } throw new AuthorizationException('You cannot review this leave request.');
    }
}
