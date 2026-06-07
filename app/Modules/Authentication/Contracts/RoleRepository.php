<?php

namespace App\Modules\Authentication\Contracts;

use App\Modules\Authentication\Models\User;

interface RoleRepository
{
    public function assign(User $user, string $role): void;

    public function sync(User $user, string $role): void;
}
