<?php

namespace App\Modules\Finance\Contracts;

use App\Modules\Finance\Models\FinancialPeriod;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface FinanceRepository
{
    public function list(string $model, int $companyId, array $with = []): Collection;

    public function create(string $model, array $attributes): Model;

    public function openPeriod(int $companyId, string $date): FinancialPeriod;

    public function nextNumber(int $companyId, string $prefix): string;
}
