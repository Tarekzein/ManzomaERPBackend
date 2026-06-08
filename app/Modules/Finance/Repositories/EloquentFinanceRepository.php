<?php

namespace App\Modules\Finance\Repositories;

use App\Modules\Finance\Contracts\FinanceRepository;
use App\Modules\Finance\Models\FinancialPeriod;
use App\Modules\Finance\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EloquentFinanceRepository implements FinanceRepository
{
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
        return $prefix.'-'.now()->format('Y').'-'.str_pad((string) (JournalEntry::where('company_id', $companyId)->count() + 1), 6, '0', STR_PAD_LEFT);
    }
}
