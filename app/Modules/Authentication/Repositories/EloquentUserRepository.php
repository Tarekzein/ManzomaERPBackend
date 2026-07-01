<?php

namespace App\Modules\Authentication\Repositories;

use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\EffectiveAccessService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentUserRepository implements UserRepository
{
    public function __construct(private readonly EffectiveAccessService $access) {}

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
        $paginator = User::query()
            ->with('company', 'roles.permissions', 'customRole', 'permissions', 'permissionOverrides', 'socialAccounts')
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (User $user) => $this->loadProfile($user));

        return $paginator;
    }

    public function loadProfile(User $user): User
    {
        $user->load('company.subscription.plan.features', 'roles.permissions', 'customRole', 'permissions', 'permissionOverrides', 'socialAccounts');
        $user->setAttribute('access', $this->access->effectiveAccess($user));
        $user->syncOriginalAttribute('access');

        return $user;
    }
}
