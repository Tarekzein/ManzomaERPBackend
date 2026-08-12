<?php

namespace App\Modules\Companies\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Contracts\CompanyRepository;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Platform\Services\CompanyContext;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Services\CompanySubscriptionService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CompanyService
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly CompanySubscriptionService $subscriptions,
        private readonly FinanceSetupService $financeSetup,
        private readonly InventorySetupService $inventorySetup,
        private readonly CompanyContext $context,
    ) {}

    public function create(
        CreateCompanyData $data,
        string $planSlug,
        bool $active = true,
        ?Organization $organization = null,
    ): Company {
        return $this->companies->create([
            'organization_id' => $organization?->getKey(),
            'name' => $data->name,
            'plan' => $planSlug,
            'timezone' => $data->timezone,
            'locale' => $data->locale,
            'currency' => $data->currency,
            'is_active' => $active,
        ]);
    }

    public function createFromAdmin(
        User $actor,
        CreateCompanyData $data,
        string $planSlug,
        string $billingCycle,
        bool $active = true,
    ): Company {
        abort_unless($actor->isSuperAdmin(), 403);

        return DB::transaction(function () use ($actor, $data, $planSlug, $billingCycle, $active) {
            $organization = Organization::query()->create([
                'name' => $data->name,
                'status' => Organization::STATUS_ACTIVE,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'currency' => $data->currency,
                'created_by_user_id' => $actor->getKey(),
                'settings' => [],
            ]);
            $company = $this->create($data, $planSlug, $active, $organization);
            $this->subscriptions->start(
                $company,
                new SubscribeData($planSlug, $billingCycle),
                ['source' => 'admin_created', 'created_by_user_id' => $actor->id],
            );
            $this->financeSetup->provision($company);
            $this->inventorySetup->provision($company);

            return $company->refresh()
                ->load(['organization', 'subscription.plan.features'])
                ->loadCount(['members as users_count' => fn ($query) => $query->where('company_memberships.status', 'active')]);
        });
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
        $company = $this->context->companyFor($actor);
        abort_unless($company, 422, 'A company is required.');
        abort_unless(
            $actor->isSuperAdmin()
            || $this->context->hasCompanyRole($actor, UserRole::CompanyAdmin->value)
            || $actor->can('companies.edit'),
            403,
        );

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

        // Stamp the suspension so this legacy route and the organization
        // suspend endpoint leave the company in the same state; suspended_at
        // is what tells a suspension apart from an unpaid registration.
        return $this->companies->save($company, [
            'is_active' => $active,
            'suspended_at' => $active ? null : ($company->suspended_at ?: now()),
            'suspension_reason' => $active ? null : $company->suspension_reason,
            'suspended_by_user_id' => $active ? null : $actor->getKey(),
        ]);
    }

    private function ensureCanManage(User $actor, Company $company): void
    {
        abort_unless(
            $actor->isSuperAdmin()
            || ($this->context->companyIdFor($actor) === (int) $company->id && $actor->can('companies.edit')),
            403,
        );
    }
}
