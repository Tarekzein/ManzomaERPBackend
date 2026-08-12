<?php

namespace App\Modules\Authentication\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Spatie\Permission\Models\Permission;

class UserManagementPolicy
{
    /**
     * Organization standing. A user is never manageable by someone who ranks
     * below them, which is what keeps the organization owner out of reach of
     * the Company Admin of any single workspace.
     */
    private const ORGANIZATION_RANKS = [
        OrganizationMembership::ROLE_OWNER => 3,
        OrganizationMembership::ROLE_ADMIN => 2,
        OrganizationMembership::ROLE_BILLING_ADMIN => 1,
        OrganizationMembership::ROLE_MEMBER => 0,
    ];

    public function __construct(
        private readonly EffectiveAccessService $access,
        private readonly CompanyContext $context,
    ) {}

    public function ensureCanManageUsers(User $actor): void
    {
        if (! $actor->isSuperAdmin()
            && ! $this->administersWorkspaceRoles($actor)
            && ! $this->access->effectivePermissionNames($actor)->contains('users.view')) {
            throw new AuthorizationException('You are not allowed to manage users.');
        }
    }

    public function ensureCanManageTarget(User $actor, User $target): void
    {
        if (! $this->canManageTarget($actor, $target)) {
            throw new AuthorizationException('You are not allowed to manage this user.');
        }
    }

    /**
     * Whether the actor may edit, re-role, reset, deactivate or remove a user.
     *
     * Organization standing wins over workspace standing: the organization
     * owner (and the admins the owner appointed) outrank every company role,
     * so a Company Admin can never act on the account that owns the
     * organization their workspace belongs to.
     */
    public function canManageTarget(User $actor, User $target): bool
    {
        if ($actor->isSuperAdmin()) {
            return true;
        }

        if ($target->id === $actor->id || $target->isSuperAdmin()) {
            return false;
        }

        $companyId = $this->context->companyIdFor($actor);
        $targetIsMember = $companyId && ($target->companyMemberships()
            ->where('company_id', $companyId)
            ->whereIn('status', ['active', 'suspended'])
            ->exists() || (! $target->companyMemberships()->exists() && (int) $target->company_id === $companyId));

        if (! $targetIsMember) {
            return false;
        }

        return $this->organizationRank($target) <= $this->organizationRank($actor);
    }

    public function assignableRoles(User $actor): array
    {
        if ($actor->isSuperAdmin()) {
            return UserRole::values();
        }

        if ($this->administersWorkspaceRoles($actor)) {
            return UserRole::companyManagedValues();
        }

        if ($this->context->hasCompanyRole($actor, UserRole::Manager->value) && $this->access->effectivePermissionNames($actor)->contains('roles.assign')) {
            return [UserRole::Employee->value];
        }

        return [];
    }

    public function assignablePermissions(User $actor, ?UserRole $targetRole = null): array
    {
        if ($actor->isSuperAdmin()) {
            return Permission::query()->orderBy('name')->pluck('name')->all();
        }

        $permissions = $this->administersWorkspaceRoles($actor)
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
            $companyId = $this->context->companyIdFor($actor);
            if (! $companyId) {
                throw new AuthorizationException('A company workspace is required for this role.');
            }

            return $companyId;
        }

        if ($requestedCompanyId === null) {
            throw new AuthorizationException('A company is required for this role.');
        }

        return $requestedCompanyId;
    }

    /**
     * Who may hand out workspace roles: the workspace administrator, and the
     * organization owner/admin who provisions those workspaces. Both stay
     * inside the company-level role set returned by assignableRoles().
     */
    private function organizationRank(User $user): int
    {
        $role = $this->context->organizationMembershipFor($user)?->role;

        return self::ORGANIZATION_RANKS[$role] ?? 0;
    }

    private function administersWorkspaceRoles(User $actor): bool
    {
        return $this->context->hasCompanyRole($actor, UserRole::CompanyAdmin->value)
            || in_array(
                $this->context->organizationMembershipFor($actor)?->role,
                [OrganizationMembership::ROLE_OWNER, OrganizationMembership::ROLE_ADMIN],
                true,
            );
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
