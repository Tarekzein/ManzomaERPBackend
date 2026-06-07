<?php

namespace App\Modules\Companies\Repositories;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\Models\Company;

class EloquentCompanyRepository implements CompanyRepository
{
    public function create(array $attributes): Company
    {
        return Company::create($attributes);
    }
}
