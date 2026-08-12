<?php

namespace App\Modules\Finance\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class FinancePolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function companyId(User $user, string $permission = 'finance.view'): int
    {
        $companyId = $this->context->companyIdFor($user);

        if ($companyId === null || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this finance operation.');
        }

        return $companyId;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'finance.edit'): int
    {
        $companyId = $this->companyId($user, $permission);
        if ((int) $model->getAttribute('company_id') !== $companyId) {
            throw new AuthorizationException('This finance record belongs to another company.');
        }

        return $companyId;
    }
}
