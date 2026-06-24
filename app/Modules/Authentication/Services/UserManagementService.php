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
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class UserManagementService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserManagementPolicy $policy,
        private readonly EmployeeProfileService $profiles,
        private readonly EffectiveAccessService $access,
    ) {}

    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        $this->policy->ensureCanManageUsers($actor);

        return $this->users->paginate($actor->isSuperAdmin() ? null : $actor->company_id, $perPage);
    }

    public function assignableRoles(User $actor): array
    {
        $this->policy->ensureCanManageUsers($actor);

        return collect($this->policy->assignableRoles($actor))
            ->map(fn (string $role) => [
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

        if ($companyId) {
            $company = Company::with('subscription.plan')->findOrFail($companyId);
            $limit = $company->subscription?->plan?->max_users;

            if ($limit !== null && $company->users()->count() >= $limit) {
                throw ValidationException::withMessages([
                    'company_id' => ['This company has reached its subscription user limit.'],
                ]);
            }
        }

        return DB::transaction(function () use ($actor, $data, $companyId) {
            $user = $this->users->create([
                'company_id' => $companyId,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
                'must_change_password' => true,
                'is_active' => true,
            ]);

            $this->roles->assign($user, $data->role->value);
            $this->syncPermissionOverrides($actor, $user, $data->role, $data->allowedPermissions, $data->deniedPermissions);
            $this->profiles->ensureForUser($user->load('company'));

            return $this->users->loadProfile($user);
        });
    }

    public function updateRole(User $actor, User $target, UserRole $role, ?int $companyId, ?array $allowedPermissions = null, ?array $deniedPermissions = null): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($this->can($actor, 'users.edit'), 403);

        return DB::transaction(function () use ($actor, $target, $role, $companyId, $allowedPermissions, $deniedPermissions) {
            $this->users->save($target, [
                'company_id' => $this->policy->resolveCompanyId($actor, $role, $companyId ?? $target->company_id),
            ]);
            $this->roles->sync($target, $role->value);
            $this->syncPermissionOverrides($actor, $target, $role, $allowedPermissions, $deniedPermissions);
            $this->profiles->ensureForUser($target->refresh()->load('company'));

            return $this->users->loadProfile($target);
        });
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

        $target = $this->users->save($target, [
            'is_active' => $active,
            'deactivated_at' => $active ? null : now(),
        ]);

        if (! $active) {
            $target->tokens()->delete();
        }

        return $this->users->loadProfile($target);
    }

    public function remove(User $actor, User $target): User
    {
        abort_unless($this->can($actor, 'users.delete'), 403);

        return $this->setActive($actor, $target, false);
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

    private function syncPermissionOverrides(User $actor, User $target, UserRole $targetRole, ?array $allowedPermissions, ?array $deniedPermissions): void
    {
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
}
