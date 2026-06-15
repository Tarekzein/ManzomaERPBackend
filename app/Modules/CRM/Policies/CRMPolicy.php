<?php

namespace App\Modules\CRM\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CRMPolicy
{
    public function companyId(User $user, string $permission = 'crm.view', ?int $requestedCompanyId = null): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this CRM operation.');
        }

        if ($user->isSuperAdmin()) {
            $companyId = $requestedCompanyId ?: Company::query()->value('id');
            if (! $companyId || ! Company::whereKey($companyId)->exists()) {
                throw new AuthorizationException('A valid company is required for this CRM operation.');
            }

            return (int) $companyId;
        }

        if ($user->company_id === null) {
            throw new AuthorizationException('A company assignment is required for this CRM operation.');
        }

        return $user->company_id;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'crm.edit'): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to modify this CRM record.');
        }

        if ($user->isSuperAdmin()) {
            return (int) $model->getAttribute('company_id');
        }

        if ((int) $model->getAttribute('company_id') !== (int) $user->company_id) {
            throw new AuthorizationException('This CRM record belongs to another company.');
        }

        return (int) $user->company_id;
    }
}
