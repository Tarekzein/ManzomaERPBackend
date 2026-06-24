<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Models\UserPermissionOverride;
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomRoleService
{
    public function __construct(
        private readonly UserManagementService $users,
        private readonly EffectiveAccessService $access,
    ) {}

    public function list(User $actor)
    {
        $this->ensureCanManage($actor);

        return CompanyCustomRole::where('company_id', $actor->company_id)->withCount('users')->orderBy('name')->get();
    }

    public function save(User $actor, array $data, ?CompanyCustomRole $role = null): CompanyCustomRole
    {
        $this->ensureCanManage($actor);
        $role ??= new CompanyCustomRole(['company_id' => $actor->company_id]);
        abort_unless($role->company_id === $actor->company_id, 404);

        return DB::transaction(function () use ($actor, $role, $data) {
            $permissions = $this->validatedPermissions($actor, $data['permissions']);
            $role->fill($data + ['permissions' => $permissions])->save();
            $role->users()->each(fn (User $user) => $this->applyCustomRolePermissions($user, $permissions, $role->id));

            return $role->refresh()->loadCount('users');
        });
    }

    public function delete(User $actor, CompanyCustomRole $role): void
    {
        $this->ensureCanManage($actor);
        abort_unless($role->company_id === $actor->company_id, 404);
        DB::transaction(function () use ($role) {
            $role->users()->each(function (User $user) {
                $user->syncPermissions([]);
                $user->update(['custom_role_id' => null]);
            });
            $role->delete();
        });
    }

    public function assign(User $actor, User $user, CompanyCustomRole $role): User
    {
        $this->ensureCanManage($actor);
        abort_unless($user->company_id === $actor->company_id && $role->company_id === $actor->company_id, 404);

        DB::transaction(function () use ($user, $role) {
            $user->syncRoles([]);
            $this->applyCustomRolePermissions($user, $role->permissions ?? [], $role->id);
        });

        return $user->refresh()->load('customRole', 'permissions');
    }

    private function ensureCanManage(User $actor): void
    {
        if (! $actor->company_id || ! $this->access->effectivePermissionNames($actor)->contains('roles.assign')) {
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

    private function applyCustomRolePermissions(User $user, array $permissions, int $roleId): void
    {
        $user->syncPermissions([]);
        $user->permissionOverrides()->delete();
        collect($permissions)->unique()->each(fn (string $permission) => $user->permissionOverrides()->create([
            'permission_name' => $permission,
            'effect' => UserPermissionOverride::EFFECT_ALLOW,
        ]));
        $user->update(['custom_role_id' => $roleId]);
    }
}
