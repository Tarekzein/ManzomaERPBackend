<?php

namespace App\Modules\Platform\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Support\Collection;

/**
 * The platform's merged view of the tenant tree: every organization with the
 * companies underneath it, plus the roll-ups the back-office charts read.
 *
 * Super admin only. Organizations and companies are one directory here on
 * purpose — a company is never meaningful without the organization that pays
 * for it and sets its limits.
 */
class TenantDirectoryService
{
    public function __construct(
        private readonly OrganizationEntitlementService $entitlements,
        private readonly OrganizationQuotaService $quotas,
    ) {}

    /**
     * @param  array{search?: string|null, status?: string|null, plan?: string|null, include_archived?: bool}  $filters
     */
    public function directory(User $actor, array $filters = []): array
    {
        abort_unless($actor->isSuperAdmin(), 403);

        $search = trim((string) ($filters['search'] ?? ''));
        $status = $filters['status'] ?? null;
        $plan = $filters['plan'] ?? null;
        $includeArchived = (bool) ($filters['include_archived'] ?? false);

        $organizations = Organization::query()
            ->when(! $includeArchived, fn ($query) => $query->whereNull('archived_at'))
            ->when($search !== '', fn ($query) => $query->where(function ($scoped) use ($search) {
                $scoped->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('billing_email', 'like', "%{$search}%")
                    ->orWhereHas('companies', fn ($company) => $company->where('name', 'like', "%{$search}%"));
            }))
            ->with([
                'companies' => fn ($query) => $query
                    ->when(! $includeArchived, fn ($companies) => $companies->whereNull('archived_at'))
                    ->withCount([
                        'members as members_count' => fn ($members) => $members
                            ->where('company_memberships.status', CompanyMembership::STATUS_ACTIVE),
                    ])
                    ->orderByRaw('archived_at IS NULL DESC')
                    ->orderBy('name'),
            ])
            ->withCount([
                'memberships as members_count' => fn ($query) => $query->where('status', OrganizationMembership::STATUS_ACTIVE),
            ])
            ->orderBy('name')
            ->get();

        $rows = $organizations
            ->map(fn (Organization $organization) => $this->organizationRow($organization))
            ->when($status !== null && $status !== '', fn (Collection $items) => $items->filter(
                fn (array $row) => $row['status'] === $status
            ))
            ->when($plan !== null && $plan !== '', fn (Collection $items) => $items->filter(
                fn (array $row) => $row['plan_slug'] === $plan
            ))
            ->values();

        return [
            'organizations' => $rows->all(),
            'filters' => [
                'plans' => SubscriptionPlan::query()
                    ->where('is_active', true)
                    ->orderBy('sort_order')
                    ->get(['slug', 'name'])
                    ->map(fn (SubscriptionPlan $item) => ['slug' => $item->slug, 'name' => $item->name])
                    ->all(),
                'statuses' => [
                    Organization::STATUS_ACTIVE,
                    Organization::STATUS_SUSPENDED,
                    Organization::STATUS_ARCHIVED,
                ],
            ],
            'totals' => $this->totals($rows),
            'analytics' => $this->analytics($rows),
        ];
    }

    private function organizationRow(Organization $organization): array
    {
        $subscription = $this->entitlements->current($organization);
        $usage = $this->quotas->usage($organization);
        $companies = $organization->companies;

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'billing_email' => $organization->billing_email,
            'suspended_at' => $organization->suspended_at?->toISOString(),
            'suspension_reason' => $organization->suspension_reason,
            'billing_suspended_at' => $organization->billing_suspended_at?->toISOString(),
            'archived_at' => $organization->archived_at?->toISOString(),
            'created_at' => $organization->created_at?->toISOString(),
            'plan' => $subscription?->plan?->name,
            'plan_slug' => $subscription?->plan?->slug,
            'subscription_status' => $subscription?->status,
            'current_period_ends_at' => $subscription?->current_period_ends_at?->toISOString(),
            'members_count' => (int) $organization->members_count,
            'companies_count' => $companies->count(),
            'active_companies_count' => $companies
                ->filter(fn (Company $company) => $company->archived_at === null && $company->is_active)
                ->count(),
            'suspended_companies_count' => $companies
                ->filter(fn (Company $company) => $company->archived_at === null && ! $company->is_active)
                ->count(),
            'usage' => $usage,
            'companies' => $companies->map(fn (Company $company) => [
                'id' => $company->id,
                'name' => $company->name,
                'slug' => $company->workspaceKey(),
                'is_active' => (bool) $company->is_active,
                'archived_at' => $company->archived_at?->toISOString(),
                'suspended_at' => $company->suspended_at?->toISOString(),
                'suspension_reason' => $company->suspension_reason,
                'members_count' => (int) $company->members_count,
                'created_at' => $company->created_at?->toISOString(),
                'state' => $this->companyState($company),
            ])->values()->all(),
        ];
    }

    private function companyState(Company $company): string
    {
        if ($company->archived_at !== null) {
            return 'archived';
        }

        return $company->is_active ? 'active' : 'suspended';
    }

    private function totals(Collection $rows): array
    {
        return [
            'organizations' => $rows->count(),
            'active_organizations' => $rows->where('status', Organization::STATUS_ACTIVE)->count(),
            'suspended_organizations' => $rows->where('status', Organization::STATUS_SUSPENDED)->count(),
            'companies' => $rows->sum('companies_count'),
            'active_companies' => $rows->sum('active_companies_count'),
            'suspended_companies' => $rows->sum('suspended_companies_count'),
            'members' => $rows->sum('members_count'),
            'active_subscriptions' => CompanySubscription::query()
                ->whereIn('status', SubscriptionStatus::servingValues())
                ->count(),
        ];
    }

    private function analytics(Collection $rows): array
    {
        return [
            // Where the tenants sit today, for the status donut.
            'organizations_by_status' => $this->countBy($rows, 'status'),
            'companies_by_state' => collect(['active', 'suspended', 'archived'])
                ->map(fn (string $state) => [
                    'name' => $state,
                    'value' => $rows->sum(fn (array $row) => collect($row['companies'])->where('state', $state)->count()),
                ])
                ->filter(fn (array $point) => $point['value'] > 0)
                ->values()
                ->all(),
            'organizations_by_plan' => $rows
                ->groupBy(fn (array $row) => $row['plan'] ?? 'No subscription')
                ->map(fn (Collection $group, string $plan) => ['name' => $plan, 'value' => $group->count()])
                ->values()
                ->all(),
            // Which tenants are actually being used, for the ranked bars.
            'largest_organizations' => $rows
                ->sortByDesc('members_count')
                ->take(8)
                ->map(fn (array $row) => [
                    'name' => $row['name'],
                    'value' => $row['members_count'],
                    'companies' => $row['companies_count'],
                ])
                ->values()
                ->all(),
            'seat_utilisation' => $rows
                ->map(function (array $row) {
                    $users = $row['usage']['users'] ?? null;
                    $limit = $users['limit'] ?? null;
                    $used = (int) ($users['used'] ?? 0) + (int) ($users['reserved'] ?? 0);

                    return [
                        'name' => $row['name'],
                        'value' => $limit ? (int) round(($used / max($limit, 1)) * 100) : 0,
                        'used' => $used,
                        'limit' => $limit,
                    ];
                })
                ->filter(fn (array $point) => $point['limit'] !== null)
                ->sortByDesc('value')
                ->take(8)
                ->values()
                ->all(),
        ];
    }

    private function countBy(Collection $rows, string $key): array
    {
        return $rows
            ->groupBy($key)
            ->map(fn (Collection $group, string $name) => ['name' => $name, 'value' => $group->count()])
            ->values()
            ->all();
    }
}
