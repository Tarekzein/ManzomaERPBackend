<?php

namespace App\Modules\Platform\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Models\UsageMetric;
use App\Modules\Subscriptions\Services\OrganizationEntitlementService;
use App\Modules\Subscriptions\Services\OrganizationQuotaService;
use Illuminate\Database\QueryException;

class UsageService
{
    public function __construct(
        private readonly OrganizationEntitlementService $entitlements,
        private readonly OrganizationQuotaService $quotas,
    ) {}

    public function increment(int $companyId, string $metric, int $quantity = 1, array $metadata = []): void
    {
        $quantity = max($quantity, 0);
        $periodDate = today()->toDateString();
        $keys = ['company_id' => $companyId, 'metric' => $metric, 'period_date' => $periodDate];

        $updated = UsageMetric::query()->where($keys)->increment('quantity', $quantity, ['metadata' => $metadata]);
        if ($updated) {
            return;
        }

        try {
            UsageMetric::query()->create($keys + ['quantity' => $quantity, 'metadata' => $metadata]);
        } catch (QueryException) {
            UsageMetric::query()->where($keys)->increment('quantity', $quantity, ['metadata' => $metadata]);
        }
    }

    public function summary(Company $company): array
    {
        $organization = $company->organization;
        $subscription = $organization
            ? $this->entitlements->current($organization)
            : $company->subscription?->loadMissing('plan');
        $organizationUsage = $organization ? $this->quotas->usage($organization) : null;
        $storageGb = $organization
            ? data_get($this->entitlements->forOrganization($organization), 'storage_gb')
            : $subscription?->plan?->storage_gb;
        $companyIds = $organization
            ? $organization->companies()->pluck('id')
            : collect([$company->getKey()]);

        return [
            'active_users' => $organizationUsage['users']['used'] ?? $company->users()->count(),
            'max_users' => $organizationUsage['users']['limit'] ?? $subscription?->plan?->max_users,
            'companies' => $organizationUsage['companies'] ?? null,
            'reserved_users' => $organizationUsage['users']['reserved'] ?? 0,
            'api_calls_today' => UsageMetric::whereIn('company_id', $companyIds)->where('metric', 'api_calls')->whereDate('period_date', today())->sum('quantity'),
            'storage_bytes' => UsageMetric::whereIn('company_id', $companyIds)->where('metric', 'storage_bytes')->sum('quantity'),
            'storage_limit_bytes' => $storageGb ? $storageGb * 1024 * 1024 * 1024 : null,
        ];
    }
}
