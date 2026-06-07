<?php

namespace App\Modules\Authentication\Repositories;

use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentUserRepository implements UserRepository
{
    public function findByEmail(string $email): ?User
    {
        return User::where('email', $email)->first();
    }

    public function create(array $attributes): User
    {
        return User::create($attributes);
    }

    public function save(User $user, array $attributes): User
    {
        $user->forceFill($attributes)->save();

        return $user;
    }

    public function paginate(?int $companyId, int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->with('company', 'roles')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function loadProfile(User $user): User
    {
        return $user->load('company.subscription.plan.features', 'roles.permissions');
    }
}
