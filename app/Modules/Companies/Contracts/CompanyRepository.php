<?php

namespace App\Modules\Companies\Contracts;

use App\Modules\Companies\Models\Company;

interface CompanyRepository
{
    public function create(array $attributes): Company;
}
