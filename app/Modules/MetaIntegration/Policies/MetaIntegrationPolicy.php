<?php

namespace App\Modules\MetaIntegration\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class MetaIntegrationPolicy
{
    public function companyId(User $user, string $permission = 'meta.view', ?int $requestedCompanyId = null): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this Meta integration operation.');
        }

        if ($user->isSuperAdmin()) {
            // A super admin acts on the company they name, either through the
            // argument or a `company_id` on the request.
            $requestedCompanyId = $requestedCompanyId ?: (request()->integer('company_id') ?: null);

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

        if ($user->company_id === null) {
            throw new AuthorizationException('A company assignment is required for this Meta integration operation.');
        }

        return $user->company_id;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'meta.edit'): int
    {
        if (! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to modify this Meta integration record.');
        }

        if ($user->isSuperAdmin()) {
            return (int) $model->getAttribute('company_id');
        }

        if ((int) $model->getAttribute('company_id') !== (int) $user->company_id) {
            throw new AuthorizationException('This Meta integration record belongs to another company.');
        }

        return (int) $user->company_id;
    }
}
