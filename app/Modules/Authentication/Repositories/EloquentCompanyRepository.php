<?php

namespace App\Modules\Authentication\Repositories;

use App\Models\Company;
use App\Modules\Authentication\Contracts\CompanyRepository;

class EloquentCompanyRepository implements CompanyRepository
{
    public function create(array $attributes): Company
    {
        return Company::create($attributes);
    }
}
