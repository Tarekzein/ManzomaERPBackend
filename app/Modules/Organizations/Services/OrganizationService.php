<?php

namespace App\Modules\Organizations\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class OrganizationService
{
    public function __construct(
        private readonly OrganizationAccessService $access,
        private readonly OrganizationQuotaService $quotas,
        private readonly OrganizationEntitlementService $entitlements,
        private readonly FinanceSetupService $financeSetup,
        private readonly InventorySetupService $inventorySetup,
    ) {}

    public function list(User $actor, int $perPage = 15): LengthAwarePaginator
    {
        $paginator = Organization::query()
            ->when(! $actor->isSuperAdmin(), fn ($query) => $query->whereHas(
                'memberships',
                fn ($membership) => $membership
                    ->where('user_id', $actor->getKey())
                    ->where('status', OrganizationMembership::STATUS_ACTIVE)
            )->with([
                'memberships' => fn ($membership) => $membership
                    ->where('user_id', $actor->getKey())
                    ->where('status', OrganizationMembership::STATUS_ACTIVE),
            ]))
            ->whereNull('archived_at')
            ->withCount([
                'companies as active_companies_count' => fn ($query) => $query->whereNull('archived_at'),
                'memberships as active_members_count' => fn ($query) => $query->where('status', OrganizationMembership::STATUS_ACTIVE),
            ])
            ->orderBy('name')
            ->paginate(min(max($perPage, 1), 100));

        $paginator->setCollection($paginator->getCollection()->map(fn (Organization $organization) => $this->access->project(
            $actor,
            $organization,
            $organization->relationLoaded('memberships') ? $organization->memberships->first() : null,
        )));

        return $paginator;
    }

    public function show(User $actor, Organization $organization): Organization
    {
        $membership = $this->access->ensureCanView($actor, $organization);
        $limitCompanies = ! $actor->isSuperAdmin()
            && $membership?->role === OrganizationMembership::ROLE_MEMBER;

        $organization->load([
            'companies' => fn ($query) => $query
                ->when(
                    $limitCompanies,
                    fn ($companies) => $companies
                        ->whereNull('archived_at')
                        ->whereHas(
                            'companyMemberships',
                            fn ($memberships) => $memberships
                                ->where('user_id', $actor->getKey())
                                ->where('status', CompanyMembership::STATUS_ACTIVE)
                        ),
                    // Managers need archived rows and their stable IDs so the
                    // existing restore endpoint remains usable after reload.
                    fn ($companies) => $companies->orderByRaw('archived_at IS NULL DESC')
                )
                ->orderBy('name'),
        ])->loadCount([
            'companies as active_companies_count' => fn ($query) => $query->whereNull('archived_at'),
            'memberships as active_members_count' => fn ($query) => $query->where('status', OrganizationMembership::STATUS_ACTIVE),
        ]);
        $this->entitlements->projectCompanySubscriptions($organization->companies);

        if ($this->access->canViewOrganizationData($actor, $membership)) {
            $organization->setAttribute('subscription', $this->entitlements->current($organization));
            $organization->setAttribute('usage', $this->quotas->usage($organization));
        }

        return $this->access->project($actor, $organization, $membership);
    }

    public function update(User $actor, Organization $organization, array $data): Organization
    {
        $settingsChanged = array_diff(array_keys($data), ['billing_email']) !== [];
        if ($settingsChanged || ! array_key_exists('billing_email', $data)) {
            $this->access->ensureCanManage($actor, $organization);
        }
        if (array_key_exists('billing_email', $data)) {
            $this->access->ensureCanManageBilling($actor, $organization);
        }

        $organization->fill([
            'name' => $data['name'] ?? $organization->name,
            'billing_email' => $data['billing_email'] ?? $organization->billing_email,
            'timezone' => $data['timezone'] ?? $organization->timezone,
            'locale' => $data['locale'] ?? $organization->locale,
            'currency' => $data['currency'] ?? $organization->currency,
            'settings' => array_replace($organization->settings ?? [], $data['settings'] ?? []),
        ])->save();

        return $this->show($actor, $organization->refresh());
    }

    public function setStatus(User $actor, Organization $organization, string $status): Organization
    {
        abort_unless($actor->isSuperAdmin(), 403);
        abort_unless(in_array($status, [Organization::STATUS_ACTIVE, Organization::STATUS_SUSPENDED], true), 422);

        $organization->forceFill(['status' => $status])->save();

        return $this->show($actor, $organization->refresh());
    }

    public function createCompany(User $actor, Organization $organization, CreateCompanyData $data): Company
    {
        $this->access->ensureCanManage($actor, $organization);

        return $this->quotas->withinCompanyCapacity($organization, function (Organization $locked) use ($actor, $data) {
            $subscription = $this->entitlements->current($locked);

            if (! $subscription) {
                throw ValidationException::withMessages([
                    'organization' => ['An active organization subscription is required before adding a company.'],
                ]);
            }

            $company = Company::query()->create([
                'organization_id' => $locked->getKey(),
                'name' => $data->name,
                'plan' => $subscription->plan->slug,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'currency' => $data->currency,
                'is_active' => true,
                'settings' => [],
            ]);

            $role = Role::findByName(UserRole::CompanyAdmin->value, 'web');
            CompanyMembership::query()->updateOrCreate(
                ['company_id' => $company->getKey(), 'user_id' => $actor->getKey()],
                [
                    'organization_id' => $locked->getKey(),
                    'role_id' => $role->getKey(),
                    'custom_role_id' => null,
                    'status' => CompanyMembership::STATUS_ACTIVE,
                    'joined_at' => now(),
                    'invited_by_user_id' => $actor->getKey(),
                    'suspended_at' => null,
                ],
            );

            if ($actor->default_company_id === null) {
                $actor->forceFill(['default_company_id' => $company->getKey()])->saveQuietly();
            }

            $this->financeSetup->provision($company);
            $this->inventorySetup->provision($company);
            $this->quotas->reconcile($locked);

            return $this->entitlements->projectCompanySubscription($company->refresh());
        });
    }

    public function setDefaultCompany(User $actor, Company $company): Company
    {
        if (! $actor->isSuperAdmin()) {
            $membership = CompanyMembership::query()
                ->where('company_id', $company->getKey())
                ->where('user_id', $actor->getKey())
                ->where('status', CompanyMembership::STATUS_ACTIVE)
                ->first();

            if (! $membership || $company->archived_at !== null || ! $company->is_active) {
                throw ValidationException::withMessages([
                    'company_id' => ['The selected company is not an available workspace.'],
                ]);
            }
        }

        $actor->forceFill(['default_company_id' => $company->getKey()])->saveQuietly();

        return $this->entitlements->projectCompanySubscription($company);
    }

    public function archiveCompany(User $actor, Organization $organization, Company $company): Company
    {
        $this->access->ensureCanManage($actor, $organization);
        $this->ensureCompanyBelongsTo($organization, $company);

        return DB::transaction(function () use ($organization, $company) {
            Organization::query()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();
            $activeCount = Company::query()
                ->where('organization_id', $organization->getKey())
                ->whereNull('archived_at')
                ->lockForUpdate()
                ->count();

            if ($activeCount <= 1) {
                throw ValidationException::withMessages([
                    'company' => ['An organization must keep at least one active company.'],
                ]);
            }

            $company->forceFill(['is_active' => false, 'archived_at' => now()])->save();
            $this->quotas->reconcile($organization);

            return $this->entitlements->projectCompanySubscription($company->refresh());
        });
    }

    public function restoreCompany(User $actor, Organization $organization, Company $company): Company
    {
        $this->access->ensureCanManage($actor, $organization);
        $this->ensureCompanyBelongsTo($organization, $company);

        if ($company->archived_at === null) {
            return $this->entitlements->projectCompanySubscription($company);
        }

        return $this->quotas->withinCompanyCapacity($organization, function () use ($company) {
            $company->forceFill(['is_active' => true, 'archived_at' => null])->save();
            $this->quotas->reconcile($company->organization()->firstOrFail());

            return $this->entitlements->projectCompanySubscription($company->refresh());
        });
    }

    private function ensureCompanyBelongsTo(Organization $organization, Company $company): void
    {
        abort_unless((int) $company->organization_id === (int) $organization->getKey(), 404);
    }
}
