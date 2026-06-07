<?php

namespace App\Modules\Authentication\Repositories;

use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Models\User;
use Spatie\Permission\Models\Role;

class SpatieRoleRepository implements RoleRepository
{
    public function assign(User $user, string $role): void
    {
        Role::findOrCreate($role);
        $user->assignRole($role);
    }

    public function sync(User $user, string $role): void
    {
        Role::findOrCreate($role);
        $user->syncRoles([$role]);
    }
}
