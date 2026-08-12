<?php

namespace App\Modules\Reporting\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class ReportingPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function companyId(User $user, string $permission = 'reporting.view', ?int $requestedCompanyId = null): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this reporting operation.');
        }

        if ($user->isSuperAdmin()) {
            $selectedCompanyId = $this->context->companyId();
            if ($selectedCompanyId && $requestedCompanyId && $selectedCompanyId !== $requestedCompanyId) {
                throw new AuthorizationException('The requested company does not match the selected workspace.');
            }

            $companyId = $selectedCompanyId ?: $requestedCompanyId;
            if (! $companyId || ! Company::whereKey($companyId)->exists()) {
                throw new AuthorizationException('Specify a valid company_id for reporting.');
            }

            return (int) $companyId;
        }

        $companyId = $this->context->companyIdFor($user);
        if (! $companyId) {
            throw new AuthorizationException('A company assignment is required for reporting.');
        }

        if ($requestedCompanyId && $requestedCompanyId !== $companyId) {
            throw new AuthorizationException('The requested company does not match the selected workspace.');
        }

        return $companyId;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'reporting.edit'): int
    {
        $companyId = $this->companyId($user, $permission, (int) $model->getAttribute('company_id'));
        if ($companyId !== (int) $model->getAttribute('company_id')) {
            throw new AuthorizationException('This reporting record belongs to another company.');
        }

        return $companyId;
    }
}
