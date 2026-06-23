<?php

namespace App\Modules\Companies\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CompanyService
{
    public function __construct(private readonly CompanyRepository $companies) {}

    public function create(CreateCompanyData $data, string $planSlug, bool $active = true): Company
    {
        return $this->companies->create([
            'name' => $data->name,
            'plan' => $planSlug,
            'timezone' => $data->timezone,
            'locale' => $data->locale,
            'currency' => $data->currency,
            'is_active' => $active,
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

    public function updateSetup(User $actor, array $data): Company
    {
        $company = $actor->company;
        abort_unless($company, 422, 'A company is required.');
        abort_unless($actor->isSuperAdmin() || $actor->hasRole(UserRole::CompanyAdmin->value) || $actor->can('companies.edit'), 403);

        $settings = array_replace($company->settings ?? [], [
            'display_name' => $data['display_name'] ?? data_get($company->settings, 'display_name'),
            'address' => $data['address'] ?? data_get($company->settings, 'address'),
            'contact_email' => $data['contact_email'] ?? data_get($company->settings, 'contact_email'),
            'contact_phone' => $data['contact_phone'] ?? data_get($company->settings, 'contact_phone'),
            'onboarding_completed_at' => now()->toISOString(),
        ]);

        if (array_key_exists('logo_path', $data)) {
            $settings['logo_path'] = $data['logo_path'];
        }

        return $this->companies->save($company, [
            'name' => $data['name'] ?? $company->name,
            'settings' => $settings,
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
