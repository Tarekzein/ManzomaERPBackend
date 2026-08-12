<?php

namespace App\Modules\Subscriptions\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class SubscriptionPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function ensureCanManageCatalog(User $user): void
    {
        if (! $user->isSuperAdmin()) {
            throw new AuthorizationException('Only a super admin can manage subscription plans and features.');
        }
    }

    public function ensureCanManageCompanySubscriptions(User $user): void
    {
        if (! $user->isSuperAdmin()) {
            throw new AuthorizationException('Only a super admin can manage company subscriptions.');
        }
    }

    public function ensureCanSubscribe(User $user): void
    {
        $company = $this->context->companyFor($user);
        $organization = $company?->organization;

        if ($organization) {
            $this->ensureCanManageOrganizationBilling($user, $organization);

            return;
        }

        if ($company === null || ! $this->context->hasCompanyRole($user, UserRole::CompanyAdmin->value)) {
            throw new AuthorizationException('Only an organization owner or billing admin can manage the subscription.');
        }
    }

    public function ensureCanManageOrganizationBilling(User $user, Organization $organization): void
    {
        if ($user->isSuperAdmin()) {
            return;
        }

        $membership = DB::table('organization_memberships')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', 'active')
            ->first();

        if ($membership && in_array($membership->role, ['owner', 'billing_admin'], true)) {
            return;
        }

        // Compatibility during the dual-write rollout: before an organization
        // has any migrated memberships, retain the legacy Company Admin rule.
        $organizationHasMemberships = DB::table('organization_memberships')
            ->where('organization_id', $organization->getKey())
            ->exists();

        if (! $organizationHasMemberships
            && $this->context->companyFor($user) !== null
            && $this->context->hasCompanyRole($user, UserRole::CompanyAdmin->value)) {
            return;
        }

        throw new AuthorizationException('Only an organization owner or billing admin can manage the subscription.');
    }
}
