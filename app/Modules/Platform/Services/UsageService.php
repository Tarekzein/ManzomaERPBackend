<?php

namespace App\Modules\Platform\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Models\UsageMetric;
use Illuminate\Database\QueryException;

class UsageService
{
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
