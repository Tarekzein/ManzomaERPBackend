<?php

namespace App\Modules\Companies\Contracts;

use App\Modules\Companies\Models\Company;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CompanyRepository
{
    public function create(array $attributes): Company;

    public function paginate(string $search, int $perPage): LengthAwarePaginator;

    public function save(Company $company, array $attributes): Company;
}
