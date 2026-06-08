<?php

namespace App\Modules\Companies\Services;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;

class CompanyService
{
    public function __construct(private readonly CompanyRepository $companies) {}

    public function create(CreateCompanyData $data, string $planSlug): Company
    {
        return $this->companies->create([
            'name' => $data->name,
            'plan' => $planSlug,
            'timezone' => $data->timezone,
            'locale' => $data->locale,
            'currency' => $data->currency,
            'is_active' => true,
        ]);
    }
}
