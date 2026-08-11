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

    /** Everything a serialised user profile needs. */
    private const PROFILE_RELATIONS = [
        'company.subscription.plan.features',
        'roles.permissions',
        'customRole',
        'permissions',
        'permissionOverrides',
        'socialAccounts',
    ];

    public function paginate(?int $companyId, int $perPage): LengthAwarePaginator
    {
        // Eager-load exactly what loadProfile() would otherwise fetch per row:
        // calling it here would re-`load()` each user and re-query company,
        // subscription, plan and roles once per record.
        $paginator = User::query()
            ->with(self::PROFILE_RELATIONS)
            ->when($companyId !== null, fn ($query) => $query->where('company_id', $companyId))
            ->orderBy('name')
            ->paginate($perPage);

        $paginator->getCollection()->transform(fn (User $user) => $this->decorateProfile($user));

        return $paginator;
    }

    public function loadProfile(User $user): User
    {
        $user->load(self::PROFILE_RELATIONS);

        return $this->decorateProfile($user);
    }

    /** Attach effective access to a user whose relations are already loaded. */
    private function decorateProfile(User $user): User
    {
        $user->setAttribute('access', $this->access->effectiveAccess($user));
        $user->syncOriginalAttribute('access');

        return $user;
    }
}
