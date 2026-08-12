<?php

namespace App\Modules\MetaIntegration\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class MetaIntegrationPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function companyId(User $user, string $permission = 'meta.view', ?int $requestedCompanyId = null): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this Meta integration operation.');
        }

        if ($user->isSuperAdmin()) {
            // A super admin acts on the company they name, either through the
            // argument or a `company_id` on the request.
            $requestedCompanyId = $requestedCompanyId ?: (request()->integer('company_id') ?: null);
            $selectedCompanyId = $this->context->companyId();
            if ($selectedCompanyId && $requestedCompanyId && $selectedCompanyId !== $requestedCompanyId) {
                throw new AuthorizationException('The requested company does not match the selected workspace.');
            }

            $requestedCompanyId = $selectedCompanyId ?: $requestedCompanyId;

            // Never fall back to "the first company": a super admin would then
            // read — or disconnect — an arbitrary tenant's Meta account without
            // ever naming it.
            if (! $requestedCompanyId) {
                throw new AuthorizationException('Specify company_id to manage a company\'s Meta integration.');
            }

            if (! Company::whereKey($requestedCompanyId)->exists()) {
                throw new AuthorizationException('A valid company is required for this Meta integration operation.');
            }

            return (int) $requestedCompanyId;
        }

        $companyId = $this->context->companyIdFor($user);
        if ($companyId === null) {
            throw new AuthorizationException('A company assignment is required for this Meta integration operation.');
        }

        if ($requestedCompanyId && $requestedCompanyId !== $companyId) {
            throw new AuthorizationException('The requested company does not match the selected workspace.');
        }

        return $companyId;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'meta.edit'): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to modify this Meta integration record.');
        }

        if ($user->isSuperAdmin()) {
            $modelCompanyId = (int) $model->getAttribute('company_id');
            if ($this->context->companyId() && $this->context->companyId() !== $modelCompanyId) {
                throw new AuthorizationException('This Meta integration record belongs to another company.');
            }

            return $modelCompanyId;
        }

        $companyId = $this->context->companyIdFor($user);
        if ($companyId === null || (int) $model->getAttribute('company_id') !== $companyId) {
            throw new AuthorizationException('This Meta integration record belongs to another company.');
        }

        return $companyId;
    }
}
