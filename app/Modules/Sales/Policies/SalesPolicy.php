<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class SalesPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function companyId(User $user, string $permission = 'sales.view'): int
    {
        $companyId = $this->context->companyIdFor($user);

        if ($companyId === null || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this sales operation.');
        }

        return $companyId;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'sales.edit'): int
    {
        $companyId = $this->companyId($user, $permission);
        if ((int) $model->getAttribute('company_id') !== $companyId) {
            throw new AuthorizationException('This sales record belongs to another company.');
        }

        return $companyId;
    }
}
