<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\DTOs\CreateUserData;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Models\UserPermissionOverride;
use App\Modules\Authentication\Policies\UserManagementPolicy;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Services\EmployeeProfileService;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\CompanyMembershipPermissionOverride;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserManagementService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserManagementPolicy $policy,
        private readonly EmployeeProfileService $profiles,
        private readonly EffectiveAccessService $access,
        private readonly CompanyContext $context,
        private readonly OrganizationQuotaService $quotas,
    ) {}

    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        $this->policy->ensureCanManageUsers($actor);

        $paginator = $this->users->paginate($actor->isSuperAdmin() ? null : $this->context->companyIdFor($actor), $perPage);

        // The row actions are authorized per target, so the client is told
        // which rows it may act on instead of guessing from the role column.
        $paginator->getCollection()->transform(function (User $user) use ($actor) {
            $user->setAttribute('can_manage', $this->policy->canManageTarget($actor, $user));
            $user->syncOriginalAttribute('can_manage');

            return $user;
        });

        return $paginator;
    }

    public function assignableRoles(User $actor): array
    {
        $this->policy->ensureCanManageUsers($actor);

        $roleNames = collect($this->policy->assignableRoles($actor));
        $roleIds = Role::query()
            ->where('guard_name', 'web')
            ->whereIn('name', $roleNames)
            ->pluck('id', 'name');

        return $roleNames
            ->map(fn (string $role) => [
                'id' => $roleIds->get($role),
                'name' => $role,
                'default_permissions' => $this->filteredRoleDefaults($actor, UserRole::from($role)),
            ])
            ->values()
            ->all();
    }

    public function assignablePermissions(User $actor): array
    {
        $this->policy->ensureCanManageUsers($actor);
        abort_unless($this->can($actor, 'roles.assign') || $actor->isSuperAdmin(), 403);

        return collect($this->assignablePermissionNames($actor))
            ->map(fn (string $permission) => [
                'name' => $permission,
                'module' => $this->access->permissionModule($permission) ?? explode('.', $permission, 2)[0],
                'action' => $this->access->permissionAction($permission),
            ])
            ->values()
            ->all();
    }

    public function assignableRoleNames(User $actor): array
    {
        $this->policy->ensureCanManageUsers($actor);

        return $this->policy->assignableRoles($actor);
    }

    public function assignablePermissionNames(User $actor, ?UserRole $targetRole = null): array
    {
        $this->policy->ensureCanManageUsers($actor);

        return $this->policy->assignablePermissions($actor, $targetRole);
    }

    public function create(User $actor, CreateUserData $data): User
    {
        $this->policy->ensureCanManageUsers($actor);
        abort_unless($this->can($actor, 'users.create'), 403);
        $companyId = $this->policy->resolveCompanyId($actor, $data->role, $data->companyId);

        $company = $companyId ? Company::with('organization')->findOrFail($companyId) : null;
        $operation = function () use ($actor, $data, $companyId, $company) {
            $user = $this->users->create([
                'company_id' => $companyId,
                'default_company_id' => $companyId,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'must_change_password' => true,
                'is_active' => true,
            ]);

            $this->roles->assign($user, $data->role->value);
            if ($company?->organization) {
                $this->ensureMemberships($actor, $user, $company, $data->role);
            }
            $this->syncPermissionOverrides(
                $actor,
                $user,
                $data->role,
                $data->allowedPermissions,
                $data->deniedPermissions,
                $companyId,
            );
            $this->profiles->ensureForUser($user, $company);

            return $this->users->loadProfile($user);
        };

        if ($company?->organization) {
            $user = $this->quotas->withinUserCapacity($company->organization, null, fn () => $operation());
            $this->quotas->reconcile($company->organization);

            return $user;
        }

        return DB::transaction($operation);
    }

    public function updateRole(User $actor, User $target, UserRole $role, ?int $companyId, ?array $allowedPermissions = null, ?array $deniedPermissions = null): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($this->can($actor, 'users.edit'), 403);

        $resolvedCompanyId = $this->policy->resolveCompanyId($actor, $role, $companyId ?? $target->company_id);
        $company = $resolvedCompanyId ? Company::with('organization')->findOrFail($resolvedCompanyId) : null;
        $operation = function () use ($actor, $target, $role, $allowedPermissions, $deniedPermissions, $company, $resolvedCompanyId) {
            if ($company?->organization) {
                $this->ensureMemberships($actor, $target, $company, $role);
            }

            if (! $target->company_id && $resolvedCompanyId) {
                $this->users->save($target, [
                    'company_id' => $resolvedCompanyId,
                    'default_company_id' => $resolvedCompanyId,
                ]);
            }

            if (! $resolvedCompanyId || $this->usesLegacyProjection($target, $resolvedCompanyId)) {
                $this->roles->sync($target, $role->value);
            }

            $this->syncPermissionOverrides(
                $actor,
                $target,
                $role,
                $allowedPermissions,
                $deniedPermissions,
                $resolvedCompanyId,
            );
            $this->profiles->ensureForUser($target, $company);

            return $this->users->loadProfile($target);
        };

        if ($company?->organization) {
            $user = $this->quotas->withinUserCapacity($company->organization, $target, fn () => $operation());
            $this->quotas->reconcile($company->organization);

            return $user;
        }

        return DB::transaction($operation);
    }

    public function update(User $actor, User $target, array $data): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($this->can($actor, 'users.edit'), 403);

        return $this->users->loadProfile($this->users->save($target, [
            'name' => $data['name'] ?? $target->name,
            'email' => $data['email'] ?? $target->email,
        ]));
    }

    public function setActive(User $actor, User $target, bool $active): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($this->can($actor, 'users.edit'), 403);

        $companyId = $this->context->companyIdFor($actor);
        $membership = $companyId
            ? $target->companyMemberships()->with('organization')->where('company_id', $companyId)->first()
            : null;

        if ($membership) {
            if ($active && ! $target->organizationMemberships()
                ->where('organization_id', $membership->organization_id)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->exists()) {
                throw ValidationException::withMessages([
                    'user' => ['Activate the organization membership before granting company access.'],
                ]);
            }

            $operation = function () use ($target, $membership, $active) {
                $membership->forceFill([
                    'status' => $active ? CompanyMembership::STATUS_ACTIVE : CompanyMembership::STATUS_SUSPENDED,
                    'suspended_at' => $active ? null : now(),
                ])->save();

                if (! $active && (int) $target->default_company_id === (int) $membership->company_id) {
                    $target->forceFill(['default_company_id' => $target->companyMemberships()
                        ->where('status', CompanyMembership::STATUS_ACTIVE)
                        ->value('company_id')])->save();
                }
            };

            DB::transaction($operation);

            if ($membership->organization) {
                $this->quotas->reconcile($membership->organization);
            }
        } else {
            $target = $this->users->save($target, [
                'is_active' => $active,
                'deactivated_at' => $active ? null : now(),
            ]);
        }

        if (! $target->is_active) {
            $target->tokens()->delete();
        }

        return $this->users->loadProfile($target);
    }

    public function remove(User $actor, User $target): User
    {
        abort_unless($this->can($actor, 'users.delete'), 403);

        $this->setActive($actor, $target, false);

        if ($companyId = $this->context->companyIdFor($actor)) {
            $target->companyMemberships()
                ->where('company_id', $companyId)
                ->update([
                    'status' => CompanyMembership::STATUS_REMOVED,
                    'suspended_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        return $this->users->loadProfile($target->refresh());
    }

    public function forcePasswordReset(User $actor, User $target): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($this->can($actor, 'auth.force_password_reset'), 403);

        $target->forceFill([
            'must_change_password' => true,
        ])->save();
        $target->tokens()->delete();

        return $this->users->loadProfile($target);
    }

    private function syncPermissionOverrides(
        User $actor,
        User $target,
        UserRole $targetRole,
        ?array $allowedPermissions,
        ?array $deniedPermissions,
        ?int $companyId,
    ): void {
        if ($allowedPermissions === null && $deniedPermissions === null) {
            return;
        }

        abort_unless($this->can($actor, 'roles.assign') || $actor->isSuperAdmin(), 403);

        $allowedPermissions = collect($allowedPermissions ?? [])->filter()->unique()->values();
        $deniedPermissions = collect($deniedPermissions ?? [])->filter()->unique()->values();
        $overlap = $allowedPermissions->intersect($deniedPermissions)->values();

        if ($overlap->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['A permission cannot be both allowed and denied: '.$overlap->implode(', ')],
            ]);
        }

        $assignable = collect($this->policy->assignablePermissions($actor, $targetRole));
        $invalid = $allowedPermissions->merge($deniedPermissions)->diff($assignable)->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['You cannot assign these permissions: '.$invalid->implode(', ')],
            ]);
        }

        $membership = $companyId ? $target->companyMemberships()->where('company_id', $companyId)->first() : null;
        if ($membership) {
            $membership->permissionOverrides()->delete();
            $allowedPermissions->each(fn (string $permission) => $membership->permissionOverrides()->create([
                'permission_name' => $permission,
                'effect' => CompanyMembershipPermissionOverride::EFFECT_ALLOW,
            ]));
            $deniedPermissions->each(fn (string $permission) => $membership->permissionOverrides()->create([
                'permission_name' => $permission,
                'effect' => CompanyMembershipPermissionOverride::EFFECT_DENY,
            ]));
            $membership->forceFill(['custom_role_id' => null])->save();
        }

        if (! $membership || ($companyId && $this->usesLegacyProjection($target, $companyId))) {
            $target->syncPermissions([]);
            $target->permissionOverrides()->delete();

            $allowedPermissions->each(fn (string $permission) => $target->permissionOverrides()->create([
                'permission_name' => $permission,
                'effect' => UserPermissionOverride::EFFECT_ALLOW,
            ]));
            $deniedPermissions->each(fn (string $permission) => $target->permissionOverrides()->create([
                'permission_name' => $permission,
                'effect' => UserPermissionOverride::EFFECT_DENY,
            ]));

            $target->forceFill(['custom_role_id' => null])->save();
        }
    }

    private function filteredRoleDefaults(User $actor, UserRole $role): array
    {
        $rolePermissions = Permission::query()
            ->whereHas('roles', fn ($query) => $query->where('name', $role->value))
            ->orderBy('name')
            ->pluck('name');

        if ($actor->isSuperAdmin()) {
            return $rolePermissions->all();
        }

        $assignable = collect($this->policy->assignablePermissions($actor, $role));

        return $rolePermissions->intersect($assignable)->values()->all();
    }

    private function can(User $actor, string $permission): bool
    {
        return $actor->isSuperAdmin() || $this->access->effectivePermissionNames($actor)->contains($permission);
    }

    private function ensureMemberships(User $actor, User $user, Company $company, UserRole $role): CompanyMembership
    {
        $organizationMembership = OrganizationMembership::query()->firstOrNew(
            [
                'organization_id' => $company->organization_id,
                'user_id' => $user->getKey(),
            ],
        );
        $needsOwner = $actor->isSuperAdmin()
            && ! $user->isSuperAdmin()
            && ! OrganizationMembership::query()
                ->where('organization_id', $company->organization_id)
                ->where('role', OrganizationMembership::ROLE_OWNER)
                ->where('status', OrganizationMembership::STATUS_ACTIVE)
                ->exists();
        if ($organizationMembership->exists
            && $organizationMembership->status !== OrganizationMembership::STATUS_ACTIVE
            && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'user' => ['Activate the organization membership before assigning company access.'],
            ]);
        }
        $organizationMembership->forceFill([
            'role' => $needsOwner
                ? OrganizationMembership::ROLE_OWNER
                : ($organizationMembership->exists
                ? $organizationMembership->role
                : OrganizationMembership::ROLE_MEMBER),
            'status' => $organizationMembership->exists && ! $actor->isSuperAdmin()
                ? $organizationMembership->status
                : OrganizationMembership::STATUS_ACTIVE,
            'joined_at' => $organizationMembership->joined_at ?: now(),
            'invited_by_user_id' => $organizationMembership->invited_by_user_id ?: $actor->getKey(),
            'suspended_at' => null,
        ])->save();

        $spatieRole = Role::findOrCreate($role->value, 'web');

        $membership = CompanyMembership::query()->firstOrNew(
            [
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
            ],
        );
        $membership->forceFill([
            'organization_id' => $company->organization_id,
            'role_id' => $spatieRole->getKey(),
            'custom_role_id' => null,
            'status' => $membership->exists ? $membership->status : CompanyMembership::STATUS_ACTIVE,
            'joined_at' => $membership->joined_at ?: now(),
            'invited_by_user_id' => $membership->invited_by_user_id ?: $actor->getKey(),
            'suspended_at' => $membership->exists ? $membership->suspended_at : null,
        ])->save();

        return $membership;
    }

    private function usesLegacyProjection(User $user, int $companyId): bool
    {
        return (int) $user->company_id === $companyId;
    }
}
