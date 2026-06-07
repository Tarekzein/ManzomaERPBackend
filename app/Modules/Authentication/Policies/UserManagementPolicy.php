<?php

namespace App\Modules\Authentication\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class UserManagementPolicy
{
    public function ensureCanManageUsers(User $actor): void
    {
        if (! $actor->isSuperAdmin() && ! $actor->hasRole(UserRole::CompanyAdmin->value)) {
            throw new AuthorizationException('You are not allowed to manage users.');
        }
    }

    public function ensureCanManageTarget(User $actor, User $target): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        if ($target->id === $actor->id || $target->company_id !== $actor->company_id || $target->isSuperAdmin()) {
            throw new AuthorizationException('You are not allowed to manage this user.');
        }
    }

    public function assignableRoles(User $actor): array
    {
        return $actor->isSuperAdmin() ? UserRole::values() : UserRole::companyManagedValues();
    }

    public function resolveCompanyId(User $actor, UserRole $role, ?int $requestedCompanyId): ?int
    {
        if (! $role->requiresCompany()) {
            return null;
        }

        if (! $actor->isSuperAdmin()) {
            return $actor->company_id;
        }

        if ($requestedCompanyId === null) {
            throw new AuthorizationException('A company is required for this role.');
        }

        return $requestedCompanyId;
    }
}
