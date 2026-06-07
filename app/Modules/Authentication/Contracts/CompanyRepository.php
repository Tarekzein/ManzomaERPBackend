<?php

namespace App\Modules\Authentication\Contracts;

use App\Models\Company;

interface CompanyRepository
{
    public function create(array $attributes): Company;
}
