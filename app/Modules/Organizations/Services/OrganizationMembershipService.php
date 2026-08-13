<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\CompanyMembershipPermissionOverride;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class OrganizationMembershipService
{
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly OrganizationQuotaService $quotas,
    ) {}

    public function list(User $actor, Organization $organization, int $perPage = 25): LengthAwarePaginator
    {
        $this->access->ensureCanManage($actor, $organization);

        return $organization->memberships()
            ->with([
                'user',
                'user.companyMemberships' => fn ($query) => $query
                    ->where('organization_id', $organization->getKey())
                    ->with(['company', 'role', 'customRole', 'permissionOverrides']),
            ])
            ->orderByRaw("FIELD(role, 'owner', 'admin', 'billing_admin', 'member')")
            ->orderBy('id')
            ->paginate(min(max($perPage, 1), 100));
    }

    public function update(
        User $actor,
        Organization $organization,
        OrganizationMembership $membership,
        array $data,
    ): OrganizationMembership {
        $actorMembership = $this->access->ensureCanManage($actor, $organization);
        $this->ensureMembershipBelongsTo($organization, $membership);

        if (! $actor->isSuperAdmin()
            && ($data['role'] ?? $membership->role) === OrganizationMembership::ROLE_OWNER
            && $actorMembership?->role !== OrganizationMembership::ROLE_OWNER) {
            throw new AuthorizationException('Only an organization owner can grant owner access.');
        }

        $operation = function () use ($organization, $membership, $data) {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $locked = OrganizationMembership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $newRole = $data['role'] ?? $locked->role;
            $newStatus = $data['status'] ?? $locked->status;

            if ($newRole === OrganizationMembership::ROLE_BILLING_ADMIN
                && $newStatus === OrganizationMembership::STATUS_ACTIVE
                && ! CompanyMembership::query()
                    ->where('organization_id', $locked->organization_id)
                    ->where('user_id', $locked->user_id)
                    ->where('status', CompanyMembership::STATUS_ACTIVE)
                    ->whereHas('company', fn ($query) => $query->whereNull('archived_at'))
                    ->exists()) {
                throw ValidationException::withMessages([
                    'role' => ['Assign this member to at least one company workspace before granting billing access.'],
                ]);
            }

            if ($locked->role === OrganizationMembership::ROLE_OWNER
                && ($newRole !== OrganizationMembership::ROLE_OWNER || $newStatus !== OrganizationMembership::STATUS_ACTIVE)) {
                $otherOwners = OrganizationMembership::query()
                    ->where('organization_id', $locked->organization_id)
                    ->whereKeyNot($locked->getKey())
                    ->where('role', OrganizationMembership::ROLE_OWNER)
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
                    ->lockForUpdate()
                    ->exists();

                if (! $otherOwners) {
                    throw ValidationException::withMessages([
                        'role' => ['Assign another active owner before changing the last owner.'],
                    ]);
                }
            }

            $locked->forceFill([
                'role' => $newRole,
                'status' => $newStatus,
                'suspended_at' => $newStatus === OrganizationMembership::STATUS_SUSPENDED ? now() : null,
            ])->save();

            if ($newStatus !== OrganizationMembership::STATUS_ACTIVE) {
                CompanyMembership::query()
                    ->where('organization_id', $locked->organization_id)
                    ->where('user_id', $locked->user_id)
                    ->update([
                        'status' => CompanyMembership::STATUS_SUSPENDED,
                        'suspended_at' => now(),
                    ]);
            }

            $this->quotas->reconcile($locked->organization()->firstOrFail());

            return $locked->refresh()->load('user');
        };

        // Reactivating an organization member consumes a unique organization
        // seat. Run the state transition under the same organization lock used
        // by invitations and direct user creation.
        if (($data['status'] ?? null) === OrganizationMembership::STATUS_ACTIVE) {
            return $this->quotas->withinUserCapacity(
                $organization,
                $membership->user_id,
                fn () => $operation(),
            );
        }

        return DB::transaction($operation);
    }

    public function assignCompany(
        User $actor,
        Organization $organization,
        OrganizationMembership $membership,
        Company $company,
        array $data,
    ): CompanyMembership {
        $this->access->ensureCanManage($actor, $organization);
        $this->ensureMembershipBelongsTo($organization, $membership);
        $this->ensureCompanyBelongsTo($organization, $company);

        if ($membership->status !== OrganizationMembership::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['member' => ['Activate the organization member before assigning a company.']]);
        }

        if (! empty($data['role_id']) && ! empty($data['custom_role_id'])) {
            throw ValidationException::withMessages([
                'role_id' => ['Choose either a fixed role or a custom role, not both.'],
            ]);
        }

        $role = isset($data['role_id']) ? Role::query()->findOrFail($data['role_id']) : null;
        $customRole = isset($data['custom_role_id'])
            ? CompanyCustomRole::query()->where('company_id', $company->getKey())->findOrFail($data['custom_role_id'])
            : null;

        if ($role && ! in_array($role->name, UserRole::workspaceAssignableValues(), true)) {
            throw ValidationException::withMessages([
                'role_id' => ['Only approved workspace role templates can be assigned to a company.'],
            ]);
        }

        if (! $role && ! $customRole) {
            $role = Role::findByName(UserRole::Employee->value, 'web');
        }

        return DB::transaction(function () use ($actor, $organization, $membership, $company, $data, $role, $customRole) {
            $companyMembership = CompanyMembership::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'user_id' => $membership->user_id],
                [
                    'organization_id' => $organization->getKey(),
                    'role_id' => $role?->getKey(),
                    'custom_role_id' => $customRole?->getKey(),
                    'status' => CompanyMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                    'invited_by_user_id' => $actor->getKey(),
                    'suspended_at' => null,
                ],
            );

            if (array_key_exists('permission_overrides', $data)) {
                $names = collect($data['permission_overrides'])
                    ->pluck('permission_name')
                    ->unique()
                    ->values();

                $companyMembership->permissionOverrides()->whereNotIn('permission_name', $names)->delete();

                foreach ($data['permission_overrides'] as $override) {
                    CompanyMembershipPermissionOverride::query()->updateOrCreate(
                        [
                            'company_membership_id' => $companyMembership->getKey(),
                            'permission_name' => $override['permission_name'],
                        ],
                        ['effect' => $override['effect']],
                    );
                }
            }

            $user = $membership->user()->firstOrFail();
            if ($user->default_company_id === null) {
                $user->forceFill(['default_company_id' => $company->getKey()])->saveQuietly();
            }

            return $companyMembership->refresh()->load(['company', 'role', 'customRole', 'permissionOverrides']);
        });
    }

    public function removeCompany(
        User $actor,
        Organization $organization,
        OrganizationMembership $membership,
        Company $company,
    ): CompanyMembership {
        $this->access->ensureCanManage($actor, $organization);
        $this->ensureMembershipBelongsTo($organization, $membership);
        $this->ensureCompanyBelongsTo($organization, $company);

        $companyMembership = CompanyMembership::query()
            ->where('company_id', $company->getKey())
            ->where('user_id', $membership->user_id)
            ->firstOrFail();

        $companyMembership->forceFill([
            'status' => CompanyMembership::STATUS_REMOVED,
            'suspended_at' => now(),
        ])->save();

        $user = $membership->user()->firstOrFail();
        if ((int) $user->default_company_id === (int) $company->getKey()) {
            $nextCompanyId = CompanyMembership::query()
                ->where('user_id', $user->getKey())
                ->where('status', CompanyMembership::STATUS_ACTIVE)
                ->whereHas('company', fn ($query) => $query->whereNull('archived_at')->where('is_active', true))
                ->value('company_id');
            $user->forceFill(['default_company_id' => $nextCompanyId])->saveQuietly();
        }

        return $companyMembership->refresh();
    }

    private function ensureMembershipBelongsTo(Organization $organization, OrganizationMembership $membership): void
    {
        abort_unless((int) $membership->organization_id === (int) $organization->getKey(), 404);
    }

    private function ensureCompanyBelongsTo(Organization $organization, Company $company): void
    {
        abort_unless((int) $company->organization_id === (int) $organization->getKey(), 404);
    }
}
