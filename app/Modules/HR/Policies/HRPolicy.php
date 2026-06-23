<?php

namespace App\Modules\HR\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Platform\Services\WorkScopeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class HRPolicy
{
    public function __construct(private readonly WorkScopeService $scope) {}

    public function companyId(User $user, string $permission = 'hr.view'): int
    {
        if (! $user->company_id || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this HR operation.');
        }

        return $user->company_id;
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
        if ($user->hasRole(UserRole::CompanyAdmin->value) || $user->isSuperAdmin()) {
            return [];
        }

        return $this->scope->scopedEmployeeIds($user);
    }

    public function canViewEmployee(User $user, Employee $employee): bool
    {
        if ($user->isSuperAdmin() || $user->hasRole(UserRole::CompanyAdmin->value)) {
            return $user->isSuperAdmin() || $user->company_id === $employee->company_id;
        }

        return in_array($employee->id, $this->scope->scopedEmployeeIds($user), true);
    }

    public function canReview(User $user, Employee $employee): void
    {
        if ($user->hasRole(UserRole::CompanyAdmin->value) || $employee->manager?->user_id === $user->id) {
            return;
        } throw new AuthorizationException('You cannot review this leave request.');
    }
}
