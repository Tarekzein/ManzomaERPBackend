<?php

namespace App\Modules\Companies\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
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

    public function updateSettings(User $actor, Company $company, array $data): Company
    {
        $this->ensureCanManage($actor, $company);

        return $this->companies->save($company, [
            'name' => $data['name'] ?? $company->name,
            'timezone' => $data['timezone'] ?? $company->timezone,
            'locale' => $data['locale'] ?? $company->locale,
            'currency' => $data['currency'] ?? $company->currency,
            'settings' => array_replace($company->settings ?? [], $data['settings'] ?? []),
        ]);
    }

    public function setActive(User $actor, Company $company, bool $active): Company
    {
        abort_unless($actor->isSuperAdmin(), 403);

        return $this->companies->save($company, ['is_active' => $active]);
    }

    private function ensureCanManage(User $actor, Company $company): void
    {
        abort_unless($actor->isSuperAdmin() || ($actor->company_id === $company->id && $actor->can('companies.edit')), 403);
    }
}
