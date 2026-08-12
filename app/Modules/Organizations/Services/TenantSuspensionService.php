<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Suspension across the tenant tree.
 *
 * Three levels can be suspended, by three different people:
 *   organization — by a platform super admin
 *   company      — by a super admin or the organization owner/admin
 *   membership   — by anyone who may manage that user in that workspace
 *
 * Suspension never blocks authentication. A suspended account signs in
 * normally and is told what happened; `stateFor()` is the single answer to
 * "why is this account blocked", used by both the session payload and the
 * middleware that closes the rest of the API.
 */
class TenantSuspensionService
{
    public const SCOPE_ACCOUNT = 'account';

    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_COMPANY = 'company';

    public const SCOPE_MEMBERSHIP = 'membership';

    public function __construct(private readonly OrganizationAccessService $access) {}

    public function suspendOrganization(User $actor, Organization $organization, ?string $reason = null): Organization
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $organization->forceFill([
            'status' => Organization::STATUS_SUSPENDED,
            'suspended_at' => $organization->suspended_at ?: now(),
            'suspension_reason' => $reason,
            'suspended_by_user_id' => $actor->getKey(),
        ])->save();

        return $organization->refresh();
    }

    public function reactivateOrganization(User $actor, Organization $organization): Organization
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $organization->forceFill([
            'status' => Organization::STATUS_ACTIVE,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_by_user_id' => null,
        ])->save();

        return $organization->refresh();
    }

    public function suspendCompany(User $actor, Company $company, ?string $reason = null): Company
    {
        $this->ensureCanManageCompany($actor, $company);

        if ($company->archived_at !== null) {
            throw ValidationException::withMessages([
                'company' => ['An archived company cannot be suspended. Restore it first.'],
            ]);
        }

        $company->forceFill([
            'is_active' => false,
            'suspended_at' => $company->suspended_at ?: now(),
            'suspension_reason' => $reason,
            'suspended_by_user_id' => $actor->getKey(),
        ])->save();

        return $company->refresh();
    }

    public function reactivateCompany(User $actor, Company $company): Company
    {
        $this->ensureCanManageCompany($actor, $company);

        $organization = $company->organization()->first();
        if ($organization && $organization->status === Organization::STATUS_SUSPENDED && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'company' => ['Reactivate the organization before reopening its company workspaces.'],
            ]);
        }

        $company->forceFill([
            'is_active' => true,
            'suspended_at' => null,
            'suspension_reason' => null,
            'suspended_by_user_id' => null,
        ])->save();

        return $company->refresh();
    }

    /**
     * The suspension that currently blocks this user, or null when at least
     * one workspace is still open to them. A user who keeps one usable
     * workspace is not blocked: they simply lose the suspended one.
     */
    public function stateFor(User $user): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        // Only an explicit false is a deactivation; the column defaults to
        // true and is frequently left unset on the in-memory model.
        if ($user->is_active === false) {
            return $this->state(
                self::SCOPE_ACCOUNT,
                'Your account has been deactivated.',
                $user->deactivated_at,
                null,
            );
        }

        $memberships = $user->companyMemberships()
            ->with(['company.organization'])
            ->whereIn('status', [CompanyMembership::STATUS_ACTIVE, CompanyMembership::STATUS_SUSPENDED])
            ->get()
            ->filter(fn (CompanyMembership $membership) => $membership->company
                && $membership->company->archived_at === null);

        if ($memberships->isEmpty()) {
            return $this->legacyState($user);
        }

        $organizationRoles = $this->activeOrganizationRoles($user);
        $blocking = null;

        foreach ($memberships as $membership) {
            $reason = $this->blockingReason($membership, $organizationRoles);

            if ($reason === null) {
                return null;
            }

            // Report the highest-level cause the user is subject to.
            $blocking = $this->preferred($blocking, $reason);
        }

        return $blocking;
    }

    /** Suspension scoped to one workspace, for the workspace picker. */
    public function companyState(User $user, Company $company): ?array
    {
        if ($user->isSuperAdmin()) {
            return null;
        }

        $membership = $user->companyMemberships()
            ->where('company_id', $company->getKey())
            ->whereIn('status', [CompanyMembership::STATUS_ACTIVE, CompanyMembership::STATUS_SUSPENDED])
            ->first();

        if (! $membership) {
            return null;
        }

        $membership->setRelation('company', $company->loadMissing('organization'));

        return $this->blockingReason($membership, $this->activeOrganizationRoles($user));
    }

    private function blockingReason(CompanyMembership $membership, array $organizationRoles): ?array
    {
        $company = $membership->company;
        $organization = $company->organization;

        if ($organization && $organization->status === Organization::STATUS_SUSPENDED) {
            return $this->state(
                self::SCOPE_ORGANIZATION,
                'Your organization has been suspended by the platform administrator.',
                $organization->suspended_at,
                $organization->suspension_reason,
                $organization->name,
            );
        }

        if ($organization && ! array_key_exists((int) $organization->getKey(), $organizationRoles)) {
            return $this->state(
                self::SCOPE_MEMBERSHIP,
                'Your access to this organization has been suspended.',
                $membership->suspended_at,
                null,
                $organization->name,
            );
        }

        if ($membership->status === CompanyMembership::STATUS_SUSPENDED) {
            return $this->state(
                self::SCOPE_MEMBERSHIP,
                'Your access to this workspace has been suspended.',
                $membership->suspended_at,
                $membership->suspension_reason,
                $company->name,
            );
        }

        // suspended_at is what separates a suspension from a registration
        // that has not been activated by payment yet.
        if ($company->is_active !== true && $company->suspended_at !== null) {
            return $this->state(
                self::SCOPE_COMPANY,
                'This company workspace has been suspended.',
                $company->suspended_at,
                $company->suspension_reason,
                $company->name,
            );
        }

        return null;
    }

    /** @return array<int, string> organization id => active role */
    private function activeOrganizationRoles(User $user): array
    {
        return $user->organizationMemberships()
            ->where('status', OrganizationMembership::STATUS_ACTIVE)
            ->pluck('role', 'organization_id')
            ->all();
    }

    /** Rows not yet dual-written to memberships still have to be answerable. */
    private function legacyState(User $user): ?array
    {
        $company = $user->loadMissing('company.organization')->company;

        if (! $company) {
            return null;
        }

        if ($company->organization?->status === Organization::STATUS_SUSPENDED) {
            return $this->state(
                self::SCOPE_ORGANIZATION,
                'Your organization has been suspended by the platform administrator.',
                $company->organization->suspended_at,
                $company->organization->suspension_reason,
                $company->organization->name,
            );
        }

        if ($company->is_active !== true && $company->suspended_at !== null) {
            return $this->state(
                self::SCOPE_COMPANY,
                'This company workspace has been suspended.',
                $company->suspended_at,
                $company->suspension_reason,
                $company->name,
            );
        }

        return null;
    }

    /** Organization outranks company, which outranks a single membership. */
    private function preferred(?array $current, array $candidate): array
    {
        $rank = [
            self::SCOPE_ORGANIZATION => 3,
            self::SCOPE_COMPANY => 2,
            self::SCOPE_MEMBERSHIP => 1,
        ];

        if ($current === null) {
            return $candidate;
        }

        return ($rank[$candidate['scope']] ?? 0) > ($rank[$current['scope']] ?? 0) ? $candidate : $current;
    }

    private function state(
        string $scope,
        string $message,
        $since = null,
        ?string $reason = null,
        ?string $subject = null,
    ): array {
        return [
            'scope' => $scope,
            'message' => $message,
            'reason' => $reason,
            'subject' => $subject,
            'since' => $since?->toISOString(),
        ];
    }

    private function ensureCanManageCompany(User $actor, Company $company): void
    {
        if ($actor->isSuperAdmin()) {
            return;
        }

        $organization = $company->organization()->first();
        abort_unless($organization !== null, 403);

        // Company suspension is organization administration, so it needs an
        // organization role. Being the Company Admin of the workspace is not
        // enough to close it for everyone else.
        $this->access->ensureCanManage($actor, $organization);
    }
}
