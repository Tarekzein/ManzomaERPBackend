<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Support\Collection;

class OrganizationEntitlementService
{
    /** The one subscription that currently serves every company in an organization. */
    public function current(Organization $organization): ?CompanySubscription
    {
        return CompanySubscription::query()
            ->with('plan.features')
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->latest('id')
            ->first();
    }

    /**
     * Keep the existing `company.subscription` response contract while using
     * the organization subscription that actually serves a child workspace.
     */
    public function projectCompanySubscription(Company $company): Company
    {
        $this->projectCompanySubscriptions(collect([$company]));

        return $company;
    }

    /**
     * Project effective subscriptions in one query so company directories and
     * dashboard lists do not introduce one subscription lookup per company.
     *
     * @param  Collection<int, Company>  $companies
     * @return Collection<int, Company>
     */
    public function projectCompanySubscriptions(Collection $companies): Collection
    {
        if ($companies->isEmpty()) {
            return $companies;
        }

        $organizationIds = $companies
            ->pluck('organization_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $companyIds = $companies
            ->map(fn (Company $company) => (int) $company->getKey())
            ->unique()
            ->values();

        $subscriptions = CompanySubscription::query()
            ->with('plan.features')
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->where(function ($query) use ($organizationIds, $companyIds) {
                if ($organizationIds->isNotEmpty()) {
                    $query->whereIn('organization_id', $organizationIds);

                    if ($companyIds->isNotEmpty()) {
                        $query->orWhereIn('company_id', $companyIds);
                    }

                    return;
                }

                $query->whereIn('company_id', $companyIds);
            })
            ->latest('id')
            ->get();

        $byOrganization = $subscriptions
            ->whereNotNull('organization_id')
            ->unique('organization_id')
            ->keyBy(fn (CompanySubscription $subscription) => (int) $subscription->organization_id);
        $byCompany = $subscriptions
            ->unique('company_id')
            ->keyBy(fn (CompanySubscription $subscription) => (int) $subscription->company_id);

        return $companies->each(function (Company $company) use ($byOrganization, $byCompany): void {
            $subscription = $company->organization_id
                ? $byOrganization->get((int) $company->organization_id)
                    ?? $byCompany->get((int) $company->getKey())
                : $byCompany->get((int) $company->getKey());

            $company->setRelation('subscription', $subscription);
        });
    }

    /**
     * Record the limits that were sold when service starts. This is history:
     * `forOrganization()` reads the live plan, so a snapshot never caps an
     * organization below what its plan currently grants.
     *
     * @return array<string, int|null>
     */
    public function snapshot(SubscriptionPlan $plan): array
    {
        return [
            'max_companies' => $plan->max_companies === null ? null : (int) $plan->max_companies,
            'max_users' => $plan->max_users === null ? null : (int) $plan->max_users,
            'storage_gb' => $plan->storage_gb === null ? null : (int) $plan->storage_gb,
            'api_rate_limit_per_minute' => $plan->api_rate_limit_per_minute === null
                ? null
                : (int) $plan->api_rate_limit_per_minute,
        ];
    }

    /**
     * The plan catalog is authoritative. Raising or lowering a plan's limits
     * reaches every organization already serving on that plan, so an
     * administrator never has to touch subscription rows to grant capacity.
     * The purchase-time snapshot is only a fallback for a subscription whose
     * plan row can no longer be read.
     *
     * @return array<string, int|null>
     */
    public function forOrganization(Organization $organization): array
    {
        $subscription = $this->current($organization);

        if (! $subscription) {
            return [
                'max_companies' => 0,
                'max_users' => 0,
                'storage_gb' => 0,
                'api_rate_limit_per_minute' => null,
            ];
        }

        $plan = $subscription->relationLoaded('plan')
            ? $subscription->plan
            : $subscription->loadMissing('plan')->plan;

        if ($plan instanceof SubscriptionPlan) {
            return $this->snapshot($plan);
        }

        $fallback = [
            'max_companies' => 0,
            'max_users' => 0,
            'storage_gb' => 0,
            'api_rate_limit_per_minute' => null,
        ];
        $snapshot = (array) ($subscription->entitlements_snapshot ?? []);

        return array_replace($fallback, array_intersect_key($snapshot, $fallback));
    }
}
