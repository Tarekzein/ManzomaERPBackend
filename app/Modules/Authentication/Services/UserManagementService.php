<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\DTOs\CreateUserData;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Policies\UserManagementPolicy;
use App\Modules\Companies\Models\Company;
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

    public function create(User $actor, CreateUserData $data): User
    {
        $this->policy->ensureCanManageUsers($actor);
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

        return DB::transaction(function () use ($data, $companyId) {
            $user = $this->users->create([
                'company_id' => $companyId,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);

            $this->roles->assign($user, $data->role->value);

            return $this->users->loadProfile($user);
        });
    }

    public function updateRole(User $actor, User $target, UserRole $role, ?int $companyId): User
    {
        $this->policy->ensureCanManageUsers($actor);
        $this->policy->ensureCanManageTarget($actor, $target);

        return DB::transaction(function () use ($actor, $target, $role, $companyId) {
            $this->users->save($target, [
                'company_id' => $this->policy->resolveCompanyId($actor, $role, $companyId ?? $target->company_id),
            ]);
            $this->roles->sync($target, $role->value);

            return $this->users->loadProfile($target);
        });
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
}
