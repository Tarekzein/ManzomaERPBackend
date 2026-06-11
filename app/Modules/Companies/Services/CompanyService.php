<?php

namespace App\Modules\Companies\Services;

use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    public function list(User $actor, string $search, int $perPage): LengthAwarePaginator
    {
        if (! $actor->isSuperAdmin()) {
            throw new AuthorizationException('Only a super admin can view the company directory.');
        }

        return $this->companies->paginate($search, min(max($perPage, 1), 100));
    }
}
