<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Models\UserPermissionOverride;
use App\Modules\Authentication\Policies\UserManagementPolicy;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomRoleService
{
    public function __construct(
        private readonly UserManagementService $users,
        private readonly UserManagementPolicy $policy,
        private readonly EffectiveAccessService $access,
        private readonly CompanyContext $context,
    ) {}

    public function list(User $actor)
    {
        $this->ensureCanManage($actor);

        return CompanyCustomRole::where('company_id', $this->companyId($actor))
            ->withCount(['companyMemberships as users_count' => fn ($query) => $query->where('status', 'active')])
            ->orderBy('name')
            ->get();
    }

    public function save(User $actor, array $data, ?CompanyCustomRole $role = null): CompanyCustomRole
    {
        $this->ensureCanManage($actor);
        $companyId = $this->companyId($actor);
        $role ??= new CompanyCustomRole(['company_id' => $companyId]);
        abort_unless((int) $role->company_id === $companyId, 404);

        return DB::transaction(function () use ($actor, $role, $data) {
            $permissions = $this->validatedPermissions($actor, $data['permissions']);
            $role->fill(array_replace($data, ['permissions' => $permissions]))->save();
            $role->companyMemberships()->with('user')->each(function (CompanyMembership $membership) use ($permissions, $role) {
                if ($this->usesLegacyProjection($membership->user, (int) $membership->company_id)) {
                    $this->applyLegacyCustomRolePermissions($membership->user, $permissions, $role->id);
                }
            });
            $role->users()
                ->whereDoesntHave('companyMemberships')
                ->each(fn (User $user) => $this->applyLegacyCustomRolePermissions($user, $permissions, $role->id));

            return $role->refresh()->loadCount(['companyMemberships as users_count' => fn ($query) => $query->where('status', 'active')]);
        });
    }

    public function delete(User $actor, CompanyCustomRole $role): void
    {
        $this->ensureCanManage($actor);
        abort_unless((int) $role->company_id === $this->companyId($actor), 404);
        DB::transaction(function () use ($role) {
            $role->companyMemberships()->with('user')->each(function (CompanyMembership $membership) {
                $membership->permissionOverrides()->delete();
                $membership->forceFill(['custom_role_id' => null])->save();

                if ($this->usesLegacyProjection($membership->user, (int) $membership->company_id)) {
                    $membership->user->syncPermissions([]);
                    $membership->user->update(['custom_role_id' => null]);
                }
            });
            $role->users()->whereDoesntHave('companyMemberships')->each(function (User $user) {
                $user->syncPermissions([]);
                $user->update(['custom_role_id' => null]);
            });
            $role->delete();
        });
    }

    public function assign(User $actor, User $user, CompanyCustomRole $role): User
    {
        $this->ensureCanManage($actor);
        // Swapping someone onto a custom role rewrites their permissions, so
        // it needs the same standing check as a fixed role change.
        $this->policy->ensureCanManageTarget($actor, $user);
        $companyId = $this->companyId($actor);
        $membership = $user->companyMemberships()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->first();
        $legacy = ! $user->companyMemberships()->exists() && (int) $user->company_id === $companyId;
        abort_unless(($membership || $legacy) && (int) $role->company_id === $companyId, 404);

        DB::transaction(function () use ($user, $role, $membership, $companyId) {
            if ($membership) {
                $membership->permissionOverrides()->delete();
                $membership->forceFill([
                    'role_id' => null,
                    'custom_role_id' => $role->getKey(),
                ])->save();
            }

            if (! $membership || $this->usesLegacyProjection($user, $companyId)) {
                $user->syncRoles([]);
                $this->applyLegacyCustomRolePermissions($user, $role->permissions ?? [], $role->id);
            }
        });

        $user->refresh()->setRelation('customRole', $role);

        return $user;
    }

    private function ensureCanManage(User $actor): void
    {
        if (! $this->context->companyIdFor($actor) || ! $this->access->effectivePermissionNames($actor)->contains('roles.assign')) {
            throw new AuthorizationException('You are not allowed to manage custom roles.');
        }
    }

    private function validatedPermissions(User $actor, array $requested): array
    {
        $requested = collect($requested)->filter()->unique()->values();
        $assignable = collect($this->users->assignablePermissionNames($actor));
        $invalid = $requested->diff($assignable)->values();

        if ($invalid->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permissions' => ['You cannot assign these permissions: '.$invalid->implode(', ')],
            ]);
        }

        return $requested->values()->all();
    }

    private function applyLegacyCustomRolePermissions(User $user, array $permissions, int $roleId): void
    {
        $user->syncPermissions([]);
        $user->permissionOverrides()->delete();
        collect($permissions)->unique()->each(fn (string $permission) => $user->permissionOverrides()->create([
            'permission_name' => $permission,
            'effect' => UserPermissionOverride::EFFECT_ALLOW,
        ]));
        $user->update(['custom_role_id' => $roleId]);
    }

    private function companyId(User $actor): int
    {
        return $this->context->companyIdFor($actor)
            ?? throw new AuthorizationException('A company workspace is required.');
    }

    private function usesLegacyProjection(User $user, int $companyId): bool
    {
        return (int) $user->company_id === $companyId;
    }
}
