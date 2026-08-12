<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;

class OrganizationAccessService
{
    /**
     * Organization-level attributes. A company workspace role (Company Admin,
     * Manager, Employee) never earns these: only the organization owner and
     * the roles the owner explicitly granted see them.
     */
    public const ORGANIZATION_ONLY_ATTRIBUTES = [
        'billing_email',
        'settings',
        'created_by_user_id',
        'billing_suspended_at',
        'active_companies_count',
        'active_members_count',
    ];

    public function membership(User $user, Organization $organization): ?OrganizationMembership
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        return OrganizationMembership::query()
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $user->getKey())
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->first();
    }

    public function ensureCanView(User $user, Organization $organization): ?OrganizationMembership
    {
        $membership = $this->membership($user, $organization);

        if (! $user->isSuperAdmin() && ! $membership) {
            throw new AuthorizationException('You do not have access to this organization.');
        }

        return $membership;
    }

    public function ensureCanManage(User $user, Organization $organization): ?OrganizationMembership
    {
        $membership = $this->ensureCanView($user, $organization);

        if (! $user->isSuperAdmin() && $organization->status !== Organization::STATUS_ACTIVE) {
            throw new AuthorizationException('This organization is not active.');
        }

        if (! $user->isSuperAdmin() && ! in_array($membership?->role, [
            OrganizationMembership::ROLE_OWNER,
            OrganizationMembership::ROLE_ADMIN,
        ], true)) {
            throw new AuthorizationException('Organization owner or admin access is required.');
        }

        return $membership;
    }

    /** Whether this user may see organization-level data at all. */
    public function canViewOrganizationData(User $user, ?OrganizationMembership $membership): bool
    {
        return $user->isSuperAdmin() || in_array($membership?->role, [
            OrganizationMembership::ROLE_OWNER,
            OrganizationMembership::ROLE_ADMIN,
            OrganizationMembership::ROLE_BILLING_ADMIN,
        ], true);
    }

    /**
     * Reveal the organization-level attributes Organization hides by default.
     * A member who is only there to open a company workspace keeps the
     * name/slug/status the workspace switcher needs, and nothing more.
     */
    public function project(
        User $user,
        Organization $organization,
        ?OrganizationMembership $membership = null,
    ): Organization {
        $membership ??= $user->isSuperAdmin() ? null : $this->membership($user, $organization);

        return $this->canViewOrganizationData($user, $membership)
            ? $organization->makeVisible(self::ORGANIZATION_ONLY_ATTRIBUTES)
            : $organization;
    }

    public function ensureCanManageBilling(User $user, Organization $organization): ?OrganizationMembership
    {
        $membership = $this->ensureCanView($user, $organization);

        if (! $user->isSuperAdmin() && ! in_array($membership?->role, [
            OrganizationMembership::ROLE_OWNER,
            OrganizationMembership::ROLE_BILLING_ADMIN,
        ], true)) {
            throw new AuthorizationException('Organization billing access is required.');
        }

        return $membership;
    }
}
