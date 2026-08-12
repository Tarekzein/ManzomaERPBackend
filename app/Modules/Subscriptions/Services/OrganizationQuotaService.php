<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationInvitation;
use App\Modules\Subscriptions\Exceptions\OrganizationQuotaExceededException;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Closure;
use Illuminate\Support\Facades\DB;

class OrganizationQuotaService
{
    public function __construct(private readonly OrganizationEntitlementService $entitlements) {}

    /**
     * Execute company creation while the organization row is locked. The
     * callback must perform the insert before returning so concurrent requests
     * cannot both claim the final slot.
     */
    public function withinCompanyCapacity(Organization $organization, Closure $operation): mixed
    {
        return DB::transaction(function () use ($organization, $operation) {
            $locked = $this->lock($organization);
            $usage = $this->usage($locked);
            $this->assertAvailable('companies', $usage['companies'], 1);

            return $operation($locked);
        });
    }

    /**
     * Execute a seat-consuming operation under the organization lock. An
     * identity already active in this organization can be assigned to more
     * companies without consuming another seat.
     */
    public function withinUserCapacity(Organization $organization, User|int|null $user, Closure $operation): mixed
    {
        return DB::transaction(function () use ($organization, $user, $operation) {
            $locked = $this->lock($organization);
            $userId = $user instanceof User ? $user->getKey() : $user;

            if ($userId === null || ! $this->hasActiveSeat($locked, (int) $userId)) {
                $usage = $this->usage($locked);
                $this->assertAvailable('users', $usage['users'], 1);
            }

            return $operation($locked);
        });
    }

    /**
     * Create or extend an invitation reservation while both the organization
     * and the invitation row are locked. The resolver runs after the
     * organization lock, so an invitation that expired while the request was
     * waiting cannot be resurrected without another capacity check.
     *
     * @param  Closure(Organization): ?OrganizationInvitation  $resolveInvitation
     * @param  Closure(Organization, ?OrganizationInvitation): mixed  $operation
     */
    public function withinInvitationReservation(
        Organization $organization,
        User|int|null $user,
        Closure $resolveInvitation,
        Closure $operation,
    ): mixed {
        return DB::transaction(function () use ($organization, $user, $resolveInvitation, $operation) {
            $locked = $this->lock($organization);
            $invitation = $resolveInvitation($locked);
            $userId = $user instanceof User ? $user->getKey() : $user;
            $reservationIsCurrent = $invitation instanceof OrganizationInvitation
                && $invitation->status === OrganizationInvitation::STATUS_PENDING
                && ($invitation->expires_at === null || $invitation->expires_at->isFuture());

            if (($userId === null || ! $this->hasActiveSeat($locked, (int) $userId))
                && ! $reservationIsCurrent) {
                $usage = $this->usage($locked);
                $this->assertAvailable('users', $usage['users'], 1);
            }

            return $operation($locked, $invitation);
        });
    }

    /** Convert one already-reserved invitation into an active seat atomically. */
    public function withinInvitationAcceptance(Organization $organization, User|int|null $user, Closure $operation): mixed
    {
        return DB::transaction(function () use ($organization, $user, $operation) {
            $locked = $this->lock($organization);
            $userId = $user instanceof User ? $user->getKey() : $user;

            if ($userId === null || ! $this->hasActiveSeat($locked, (int) $userId)) {
                $usage = $this->usage($locked);
                $this->assertAvailable('users', $usage['users'], 1, 1);
            }

            return $operation($locked);
        });
    }

    /**
     * Authoritative organization usage. Pending, unexpired invitations reserve
     * seats; a user assigned to many child companies is counted only once.
     *
     * @return array{companies: array{used:int,limit:?int,remaining:?int}, users: array{used:int,reserved:int,limit:?int,remaining:?int}}
     */
    public function usage(Organization $organization): array
    {
        $limits = $this->entitlements->forOrganization($organization);
        $companiesUsed = DB::table('companies')
            ->where('organization_id', $organization->getKey())
            ->whereNull('archived_at')
            ->count();
        $usersUsed = DB::table('organization_memberships')
            ->where('organization_id', $organization->getKey())
            ->where('status', 'active')
            ->distinct()
            ->count('user_id');
        $reserved = DB::table('organization_invitations')
            ->where('organization_id', $organization->getKey())
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->distinct()
            ->count('email');

        $companyLimit = $this->nullableInt($limits['max_companies'] ?? null);
        $userLimit = $this->nullableInt($limits['max_users'] ?? null);

        return [
            'companies' => [
                'used' => $companiesUsed,
                'limit' => $companyLimit,
                'remaining' => $this->remaining($companyLimit, $companiesUsed),
            ],
            'users' => [
                'used' => $usersUsed,
                'reserved' => $reserved,
                'limit' => $userLimit,
                'remaining' => $this->remaining($userLimit, $usersUsed + $reserved),
            ],
        ];
    }

    /** Reject a customer downgrade that cannot contain current usage. */
    public function ensurePlanFits(Organization $organization, SubscriptionPlan $plan): void
    {
        $usage = $this->usage($organization);
        $limits = $this->entitlements->snapshot($plan);
        $companyLimit = $this->nullableInt($limits['max_companies']);
        $userLimit = $this->nullableInt($limits['max_users']);
        $userTotal = $usage['users']['used'] + $usage['users']['reserved'];

        if (($companyLimit !== null && $usage['companies']['used'] > $companyLimit)
            || ($userLimit !== null && $userTotal > $userLimit)) {
            throw new OrganizationQuotaExceededException(
                'DOWNGRADE_USAGE_EXCEEDED',
                'Current organization usage exceeds the selected plan limits.',
                [
                    'companies' => $usage['companies'] + ['target_limit' => $companyLimit],
                    'users' => $usage['users'] + ['target_limit' => $userLimit],
                ],
            );
        }
    }

    /** Keep the serving subscription's over-limit marker in sync. */
    public function reconcile(Organization $organization): ?CompanySubscription
    {
        $subscription = $this->entitlements->current($organization);

        if (! $subscription) {
            return null;
        }

        $usage = $this->usage($organization);
        $over = ($usage['companies']['limit'] !== null && $usage['companies']['used'] > $usage['companies']['limit'])
            || ($usage['users']['limit'] !== null
                && ($usage['users']['used'] + $usage['users']['reserved']) > $usage['users']['limit']);

        if ($over && $subscription->over_limit_since === null) {
            $subscription->forceFill(['over_limit_since' => now()])->save();
        } elseif (! $over && $subscription->over_limit_since !== null) {
            $subscription->forceFill(['over_limit_since' => null])->save();
        }

        return $subscription->refresh();
    }

    private function lock(Organization $organization): Organization
    {
        return Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
    }

    private function hasActiveSeat(Organization $organization, int $userId): bool
    {
        return DB::table('organization_memberships')
            ->where('organization_id', $organization->getKey())
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    private function assertAvailable(string $resource, array $usage, int $requested, int $reservedSeatsReleased = 0): void
    {
        $limit = $usage['limit'];
        $used = (int) $usage['used'] + max((int) ($usage['reserved'] ?? 0) - $reservedSeatsReleased, 0);

        if ($limit === null || $used + $requested <= $limit) {
            return;
        }

        $isCompany = $resource === 'companies';

        throw new OrganizationQuotaExceededException(
            $isCompany ? 'COMPANY_LIMIT_REACHED' : 'USER_LIMIT_REACHED',
            $isCompany
                ? 'The organization has reached its company limit.'
                : 'The organization has reached its user limit.',
            [
                'used' => (int) $usage['used'],
                'reserved' => (int) ($usage['reserved'] ?? 0),
                'requested' => $requested,
                'reserved_released' => $reservedSeatsReleased,
                'limit' => $limit,
                'remaining' => $usage['remaining'],
            ],
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function remaining(?int $limit, int $used): ?int
    {
        return $limit === null ? null : max($limit - $used, 0);
    }
}
