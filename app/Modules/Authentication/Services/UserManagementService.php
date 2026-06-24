<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\DTOs\CreateUserData;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Policies\UserManagementPolicy;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Services\EmployeeProfileService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserManagementPolicy $policy,
        private readonly EmployeeProfileService $profiles,
    ) {}

    public function list(User $actor, int $perPage): LengthAwarePaginator
    {
        $this->policy->ensureCanManageUsers($actor);

        return $this->users->paginate($actor->isSuperAdmin() ? null : $actor->company_id, $perPage);
    }

    public function assignableRoles(User $actor): array
    {
        $this->policy->ensureCanManageUsers($actor);

        return $this->policy->assignableRoles($actor);
    }

    public function assignablePermissions(User $actor): array
    {
        $this->policy->ensureCanManageUsers($actor);
        abort_unless($actor->can('roles.assign') || $actor->isSuperAdmin(), 403);

        return $this->policy->assignablePermissions($actor);
    }

    public function create(User $actor, CreateUserData $data): User
    {
        $this->policy->ensureCanManageUsers($actor);
        abort_unless($actor->can('users.create'), 403);
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
            $this->syncDirectPermissions($actor, $user, $data->permissions);
            $this->profiles->ensureForUser($user->load('company'));

            return $this->users->loadProfile($user);
        });
    }

    public function updateRole(User $actor, User $target, UserRole $role, ?int $companyId, ?array $permissions = null): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($actor->can('users.edit'), 403);

        return DB::transaction(function () use ($actor, $target, $role, $companyId, $permissions) {
            $this->users->save($target, [
                'company_id' => $this->policy->resolveCompanyId($actor, $role, $companyId ?? $target->company_id),
            ]);
            $this->roles->sync($target, $role->value);
            $this->syncDirectPermissions($actor, $target, $permissions);
            $this->profiles->ensureForUser($target->refresh()->load('company'));

            return $this->users->loadProfile($target);
        });
    }

    public function update(User $actor, User $target, array $data): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($actor->can('users.edit'), 403);

        return $this->users->loadProfile($this->users->save($target, [
            'name' => $data['name'] ?? $target->name,
            'email' => $data['email'] ?? $target->email,
        ]));
    }

    public function setActive(User $actor, User $target, bool $active): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($actor->can('users.edit'), 403);

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
        abort_unless($actor->can('users.delete'), 403);

        return $this->setActive($actor, $target, false);
    }

    public function forcePasswordReset(User $actor, User $target): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);
        abort_unless($actor->can('auth.force_password_reset'), 403);

        $target->forceFill([
            'must_change_password' => true,
        ])->save();
        $target->tokens()->delete();

        return $this->users->loadProfile($target);
    }

    private function syncDirectPermissions(User $actor, User $target, ?array $permissions): void
    {
        if ($permissions === null) {
            return;
        }

        abort_unless($actor->can('roles.assign') || $actor->isSuperAdmin(), 403);

        $allowed = $this->policy->assignablePermissions($actor);
        $invalid = collect($permissions)->diff($allowed)->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['You cannot assign these permissions: '.$invalid->implode(', ')],
            ]);
        }

        $target->syncPermissions($permissions);
        $target->forceFill(['custom_role_id' => null])->save();
    }
}
