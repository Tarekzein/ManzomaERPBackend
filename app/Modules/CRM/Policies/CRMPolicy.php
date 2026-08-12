<?php

namespace App\Modules\CRM\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CRMPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function companyId(User $user, string $permission = 'crm.view', ?int $requestedCompanyId = null): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this CRM operation.');
        }

        if ($user->isSuperAdmin()) {
            $selectedCompanyId = $this->context->companyId();
            if ($selectedCompanyId && $requestedCompanyId && $selectedCompanyId !== $requestedCompanyId) {
                throw new AuthorizationException('The requested company does not match the selected workspace.');
            }

            $companyId = $selectedCompanyId ?: $requestedCompanyId;
            if (! $companyId || ! Company::whereKey($companyId)->exists()) {
                throw new AuthorizationException('A valid company is required for this CRM operation.');
            }

            return (int) $companyId;
        }

        $companyId = $this->context->companyIdFor($user);
        if ($companyId === null) {
            throw new AuthorizationException('A company assignment is required for this CRM operation.');
        }

        if ($requestedCompanyId && $requestedCompanyId !== $companyId) {
            throw new AuthorizationException('The requested company does not match the selected workspace.');
        }

        return $companyId;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'crm.edit'): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to modify this CRM record.');
        }

        if ($user->isSuperAdmin()) {
            $modelCompanyId = (int) $model->getAttribute('company_id');
            if ($this->context->companyId() && $this->context->companyId() !== $modelCompanyId) {
                throw new AuthorizationException('This CRM record belongs to another company.');
            }

            return $modelCompanyId;
        }

        $companyId = $this->context->companyIdFor($user);
        if ($companyId === null || (int) $model->getAttribute('company_id') !== $companyId) {
            throw new AuthorizationException('This CRM record belongs to another company.');
        }

        return $companyId;
    }
}
