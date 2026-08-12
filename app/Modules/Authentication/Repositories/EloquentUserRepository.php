<?php

namespace App\Modules\Authentication\Repositories;

use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Platform\Services\EffectiveAccessService;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentUserRepository implements UserRepository
{
    public function __construct(
        private readonly EffectiveAccessService $access,
        private readonly CompanyContext $context,
        private readonly OrganizationEntitlementService $entitlements,
    ) {}

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
        'company',
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
            ->when($companyId !== null, fn ($query) => $query->where(function ($query) use ($companyId) {
                $query->whereHas('companyMemberships', fn ($memberships) => $memberships
                    ->where('company_id', $companyId)
                    ->whereIn('status', ['active', 'suspended']))
                    ->orWhere(function ($legacy) use ($companyId) {
                        $legacy->where('company_id', $companyId)
                            ->whereDoesntHave('companyMemberships');
                    });
            }))
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

    public function loadSessionProfile(User $user): User
    {
        $user->load([
            ...self::PROFILE_RELATIONS,
            'defaultCompany.organization',
            'organizationMemberships' => fn ($query) => $query
                ->where('status', 'active')
                ->with('organization'),
            'companyMemberships' => fn ($query) => $query
                ->where('status', 'active')
                ->with(['company.organization', 'role', 'customRole', 'permissionOverrides']),
        ]);

        $user = $this->decorateProfile($user);
        $organizations = $user->organizationMemberships
            ->filter(fn ($membership) => $membership->organization?->status !== 'archived')
            ->map(fn ($membership) => [
                'id' => $membership->organization->id,
                'slug' => $membership->organization->slug,
                'name' => $membership->organization->name,
                'status' => $membership->organization->status,
                'role' => $membership->role,
            ])->values();
        $workspaces = $user->companyMemberships
            ->filter(fn ($membership) => $membership->company
                && $membership->company->archived_at === null
                && $membership->company->organization?->status !== 'archived')
            ->map(fn ($membership) => [
                'id' => $membership->company->id,
                'slug' => $membership->company->workspaceKey(),
                'name' => $membership->company->name,
                'is_active' => $membership->company->is_active,
                'organization_id' => $membership->organization_id,
                'role' => $membership->role?->name,
                'custom_role' => $membership->customRole?->only(['id', 'name', 'description']),
            ])->values();

        // During the additive rollout, an old row may be served before the
        // idempotent membership backfill reaches it.
        if ($workspaces->isEmpty()
            && $user->company
            && ! $user->companyMemberships()->exists()) {
            $workspaces->push([
                'id' => $user->company->id,
                'slug' => $user->company->workspaceKey(),
                'name' => $user->company->name,
                'is_active' => $user->company->is_active,
                'organization_id' => $user->company->organization_id,
                'role' => $user->roles->first()?->name,
                'custom_role' => $user->customRole?->only(['id', 'name', 'description']),
            ]);
        }

        if ($organizations->isEmpty()
            && $user->company?->organization
            && ! $user->organizationMemberships()->exists()) {
            $organizations->push([
                'id' => $user->company->organization->id,
                'slug' => $user->company->organization->slug,
                'name' => $user->company->organization->name,
                'status' => $user->company->organization->status,
                'role' => null,
            ]);
        }

        $user->setAttribute('organizations', $organizations->all());
        $user->setAttribute('workspaces', $workspaces->all());
        $user->syncOriginalAttributes(['organizations', 'workspaces']);

        // The compact arrays above are the public session contract. Do not
        // accidentally serialize internal membership rows and overrides too.
        $user->unsetRelation('organizationMemberships');
        $user->unsetRelation('companyMemberships');

        return $user;
    }

    /** Attach effective access to a user whose relations are already loaded. */
    private function decorateProfile(User $user): User
    {
        if ($company = $this->context->companyFor($user)) {
            if (! $company->relationLoaded('subscription')) {
                $this->entitlements->projectCompanySubscription($company);
            }

            $user->setRelation('company', $company);
            $user->setAttribute('company_id', $company->getKey());

            if ($membership = $this->context->membershipRecordFor($user)) {
                $membership->loadMissing('role', 'customRole');
                $user->setRelation('roles', $membership->role ? collect([$membership->role]) : collect());
                $user->setRelation('customRole', $membership->customRole);
                $user->setRelation('permissions', collect());
                $user->setRelation('permissionOverrides', $membership->permissionOverrides);
                $user->setAttribute('company_membership_status', $membership->status);
                $user->syncOriginalAttribute('company_membership_status');
            }

            $user->syncOriginalAttribute('company_id');
        }

        $user->setAttribute('access', $this->access->effectiveAccess($user));
        $user->syncOriginalAttribute('access');

        return $user;
    }
}
