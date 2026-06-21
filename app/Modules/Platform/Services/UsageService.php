<?php

namespace App\Modules\Platform\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Models\UsageMetric;

class UsageService
{
    public function increment(int $companyId, string $metric, int $quantity = 1, array $metadata = []): void
    {
        $usage = UsageMetric::query()->firstOrCreate([
            'company_id' => $companyId,
            'metric' => $metric,
            'period_date' => today()->toDateString(),
        ], [
            'quantity' => 0,
            'metadata' => json_encode($metadata),
        ]);

        $usage->increment('quantity', max($quantity, 0), ['metadata' => $metadata]);
    }

    public function summary(Company $company): array
    {
        $subscription = $company->subscription?->loadMissing('plan');

        return [
            'active_users' => $company->users()->count(),
            'max_users' => $subscription?->plan?->max_users,
            'api_calls_today' => UsageMetric::where('company_id', $company->id)->where('metric', 'api_calls')->whereDate('period_date', today())->value('quantity') ?? 0,
            'storage_bytes' => UsageMetric::where('company_id', $company->id)->where('metric', 'storage_bytes')->sum('quantity'),
            'storage_limit_bytes' => $subscription?->plan?->storage_gb ? $subscription->plan->storage_gb * 1024 * 1024 * 1024 : null,
        ];
    }
}
