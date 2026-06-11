<?php

namespace App\Modules\Companies\Repositories;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EloquentCompanyRepository implements CompanyRepository
{
    public function create(array $attributes): Company
    {
        return Company::create($attributes);
    }

    public function paginate(string $search, int $perPage): LengthAwarePaginator
    {
        return Company::query()
            ->with('subscription.plan')
            ->withCount('users')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', "%{$search}%"))
            ->orderBy('name')
            ->paginate($perPage);
    }
}
