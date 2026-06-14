<?php

namespace App\Modules\HR\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class HRPolicy
{
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
        return Employee::where('company_id', $this->companyId($user))->where('user_id', $user->id)->firstOrFail();
    }

    public function canReview(User $user, Employee $employee): void
    {
        if ($user->hasAnyRole([UserRole::CompanyAdmin->value, UserRole::Manager->value]) || $employee->manager?->user_id === $user->id) {
            return;
        } throw new AuthorizationException('You cannot review this leave request.');
    }
}
