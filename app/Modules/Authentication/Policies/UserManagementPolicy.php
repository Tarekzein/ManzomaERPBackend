<?php

namespace App\Modules\Authentication\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

class UserManagementPolicy
{
    public function __construct(private readonly EffectiveAccessService $access) {}

    public function ensureCanManageUsers(User $actor): void
    {
        if (! $actor->isSuperAdmin()
            && ! $actor->hasRole(UserRole::CompanyAdmin->value)
            && ! $this->access->effectivePermissionNames($actor)->contains('users.view')) {
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
        if ($actor->isSuperAdmin()) {
            return UserRole::values();
        }

        if ($actor->hasRole(UserRole::CompanyAdmin->value)) {
            return UserRole::companyManagedValues();
        }

        if ($actor->hasRole(UserRole::Manager->value) && $this->access->effectivePermissionNames($actor)->contains('roles.assign')) {
            return [UserRole::Employee->value];
        }

        return [];
    }

    public function assignablePermissions(User $actor, ?UserRole $targetRole = null): array
    {
        if ($actor->isSuperAdmin()) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }

        $permissions = $actor->hasRole(UserRole::CompanyAdmin->value)
            ? Permission::query()
                ->orderBy('name')
                ->pluck('name')
            : $this->access->effectivePermissionNames($actor);

        return $permissions
            ->reject(fn (string $permission) => $this->isRestrictedForCompanyDelegation($permission))
            ->filter(fn (string $permission) => $this->access->permissionAllowedBySubscription($actor, $permission))
            ->unique()
            ->sort()
            ->values()
            ->all();
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

    private function isRestrictedForCompanyDelegation(string $permission): bool
    {
        if (str_starts_with($permission, 'platform.') || str_starts_with($permission, 'companies.')) {
            return true;
        }

        return in_array($permission, [
            'audit.view',
            'feature_flags.manage',
            'subscriptions.manage',
            'users.delete',
        ], true);
    }
}
