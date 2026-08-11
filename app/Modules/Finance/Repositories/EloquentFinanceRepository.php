<?php

namespace App\Modules\Finance\Repositories;

use App\Modules\Finance\Contracts\FinanceRepository;
use App\Modules\Finance\Models\FinancialPeriod;
use App\Modules\Platform\Services\DocumentNumberService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EloquentFinanceRepository implements FinanceRepository
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function list(string $model, int $companyId, array $with = []): Collection
    {
        return $model::query()->with($with)->where('company_id', $companyId)->latest('id')->get();
    }

    public function create(string $model, array $attributes): Model
    {
        return $model::create($attributes);
    }

    public function openPeriod(int $companyId, string $date): FinancialPeriod
    {
        $period = FinancialPeriod::where('company_id', $companyId)
            ->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date)->first();

        if (! $period || $period->is_locked) {
            throw ValidationException::withMessages(['entry_date' => ['The posting date must belong to an open financial period.']]);
        }

        return $period;
    }

    public function nextNumber(int $companyId, string $prefix): string
    {
        // Counting rows repeated a number after any delete and raced under
        // concurrency, both of which the unique index turned into a 500.
        return $this->numbers->next($companyId, $prefix);
    }
}
