<?php

namespace App\Modules\Reporting\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class ReportingPolicy
{
    public function companyId(User $user, string $permission = 'reporting.view', ?int $requestedCompanyId = null): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this reporting operation.');
        }

        if ($user->isSuperAdmin()) {
            $companyId = $requestedCompanyId ?: Company::query()->value('id');
            if (! $companyId || ! Company::whereKey($companyId)->exists()) {
                throw new AuthorizationException('A valid company is required for reporting.');
            }

            return (int) $companyId;
        }

        if (! $user->company_id) {
            throw new AuthorizationException('A company assignment is required for reporting.');
        }

        return (int) $user->company_id;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'reporting.edit'): int
    {
        $companyId = $this->companyId($user, $permission, (int) $model->getAttribute('company_id'));
        if (! $user->isSuperAdmin() && $companyId !== (int) $model->getAttribute('company_id')) {
            throw new AuthorizationException('This reporting record belongs to another company.');
        }

        return $companyId;
    }
}
