<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;

/**
 * Request-scoped workspace state.
 *
 * The selected company belongs to the request, never to a mutable field on the
 * user. This is what lets the same identity safely use different companies in
 * two browser tabs at the same time.
 */
class CompanyContext
{
    private ?User $actor = null;

    private ?Company $company = null;

    private ?CompanyMembership $companyMembership = null;

    private ?OrganizationMembership $organizationMembership = null;

    /** @var array<string, CompanyMembership|null> */
    private array $membershipCache = [];

    /** @var array<string, OrganizationMembership|null> */
    private array $organizationMembershipCache = [];

    /** @var array<int, bool> */
    private array $hasAnyMembershipCache = [];

    public function bind(
        User $actor,
        Company $company,
        ?CompanyMembership $companyMembership = null,
        ?OrganizationMembership $organizationMembership = null,
    ): void {
        $this->actor = $actor;
        $this->company = $company;
        $this->companyMembership = $companyMembership;
        $this->organizationMembership = $organizationMembership;
        $this->membershipCache = [];
        $this->organizationMembershipCache = [];
        $this->hasAnyMembershipCache = [];
    }

    public function clear(): void
    {
        $this->actor = null;
        $this->company = null;
        $this->companyMembership = null;
        $this->organizationMembership = null;
        $this->membershipCache = [];
        $this->organizationMembershipCache = [];
        $this->hasAnyMembershipCache = [];
    }

    public function hasCompany(): bool
    {
        return $this->company !== null;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function company(): ?Company
    {
        return $this->company;
    }

    public function companyId(): ?int
    {
        return $this->company?->getKey();
    }

    public function organization(): ?Organization
    {
        return $this->company?->organization;
    }

    public function companyMembership(): ?CompanyMembership
    {
        return $this->companyMembership;
    }

    public function organizationMembership(): ?OrganizationMembership
    {
        return $this->organizationMembership;
    }

    /** Resolve the company whose data should be projected for this user. */
    public function companyFor(User $user): ?Company
    {
        if ($this->company !== null) {
            if ($user->isSuperAdmin() || $this->membershipRecordFor($user) !== null || $this->isLegacyMember($user, $this->company)) {
                return $this->company;
            }

            return null;
        }

        $user->loadMissing('defaultCompany', 'company');
        $company = $user->defaultCompany ?: $user->company;

        if (! $company || $user->isSuperAdmin()) {
            return $company;
        }

        if ($this->hasAnyCompanyMembership($user) && $this->membershipRecordFor($user) === null) {
            return null;
        }

        return $company;
    }

    public function companyIdFor(User $user): ?int
    {
        return $this->companyFor($user)?->getKey();
    }

    /**
     * Return the active membership for the selected/default company. This also
     * works while serialising another member in a company-user listing.
     */
    public function membershipFor(User $user): ?CompanyMembership
    {
        $membership = $this->membershipRecordFor($user);

        if ($membership?->status !== CompanyMembership::STATUS_ACTIVE) {
            return null;
        }

        if ($membership->organization_id && ! $this->organizationMembershipFor($user)) {
            return null;
        }

        return $membership;
    }

    /** Active or suspended membership used for management projections. */
    public function membershipRecordFor(User $user): ?CompanyMembership
    {
        $company = $this->company;

        if ($company !== null && $this->actor?->is($user)) {
            return $this->companyMembership;
        }

        $company ??= $this->fallbackCompany($user);
        if (! $company) {
            return null;
        }

        $key = $user->getKey().':'.$company->getKey();
        if (array_key_exists($key, $this->membershipCache)) {
            return $this->membershipCache[$key];
        }

        return $this->membershipCache[$key] = $user->companyMemberships()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [CompanyMembership::STATUS_ACTIVE, CompanyMembership::STATUS_SUSPENDED])
            ->with(['role.permissions', 'customRole', 'permissionOverrides'])
            ->first();
    }

    public function hasMembershipRecordFor(User $user): bool
    {
        return $this->membershipRecordFor($user) !== null;
    }

    public function organizationMembershipFor(User $user): ?OrganizationMembership
    {
        $organizationId = $this->company?->organization_id
            ?? $this->fallbackCompany($user)?->organization_id;

        if (! $organizationId) {
            return null;
        }

        if ($this->actor?->is($user) && $this->organizationMembership?->organization_id === $organizationId) {
            return $this->organizationMembership;
        }

        $key = $user->getKey().':'.$organizationId;
        if (array_key_exists($key, $this->organizationMembershipCache)) {
            return $this->organizationMembershipCache[$key];
        }

        return $this->organizationMembershipCache[$key] = $user->organizationMemberships()
            ->where('organization_id', $organizationId)
            ->where('status', 'active')
            ->first();
    }

    public function hasCompanyRole(User $user, string $role): bool
    {
        $membership = $this->membershipFor($user);

        if ($membership) {
            return $membership->role?->name === $role;
        }

        return ! $this->hasMembershipRecordFor($user) && $user->hasRole($role);
    }

    private function fallbackCompany(User $user): ?Company
    {
        $user->loadMissing('defaultCompany', 'company');

        return $user->defaultCompany ?: $user->company;
    }

    private function isLegacyMember(User $user, Company $company): bool
    {
        if ((int) $user->company_id !== (int) $company->getKey()) {
            return false;
        }

        return ! $this->hasAnyCompanyMembership($user);
    }

    private function hasAnyCompanyMembership(User $user): bool
    {
        $key = (int) $user->getKey();

        return $this->hasAnyMembershipCache[$key]
            ??= $user->companyMemberships()->exists();
    }
}
