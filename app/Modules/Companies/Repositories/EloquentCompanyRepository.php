<?php

namespace App\Modules\Companies\Repositories;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentCompanyRepository implements CompanyRepository
{
    public function __construct(private readonly OrganizationEntitlementService $entitlements) {}

    public function create(array $attributes): Company
    {
        return Company::create($attributes);
    }

    public function paginate(string $search, int $perPage): LengthAwarePaginator
    {
        $paginator = Company::query()
            ->withCount([
                'users as legacy_users_count',
                'companyMemberships as company_memberships_count',
                'companyMemberships as active_members_count' => fn ($query) => $query->where('status', 'active'),
            ])
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($perPage);

        $this->entitlements->projectCompanySubscriptions($paginator->getCollection());
        $paginator->getCollection()->each(function (Company $company): void {
            $company->setAttribute(
                'users_count',
                $company->company_memberships_count > 0
                    ? $company->active_members_count
                    : $company->legacy_users_count,
            );
            $company->offsetUnset('legacy_users_count');
            $company->offsetUnset('company_memberships_count');
            $company->offsetUnset('active_members_count');
        });

        return $paginator;
    }

    public function save(Company $company, array $attributes): Company
    {
        $company->fill($attributes)->save();

        return $this->entitlements->projectCompanySubscription($company->refresh());
    }
}
