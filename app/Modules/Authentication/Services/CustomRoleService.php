<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;

class CustomRoleService
{
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

        return DB::transaction(function () use ($role, $data) {
            $permissions = Permission::whereIn('name', $data['permissions'])->pluck('name')->all();
            $role->fill($data + ['permissions' => $permissions])->save();
            $role->users()->each(fn (User $user) => $user->syncPermissions($permissions));

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
            $user->syncPermissions($role->permissions);
            $user->update(['custom_role_id' => $role->id]);
        });

        return $user->refresh()->load('customRole', 'permissions');
    }

    private function ensureCanManage(User $actor): void
    {
        if (! $actor->company_id || ! $actor->can('roles.assign')) {
            throw new AuthorizationException('You are not allowed to manage custom roles.');
        }
    }
}
