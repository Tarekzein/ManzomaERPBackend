<?php

namespace App\Modules\Sales\Policies;

use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class SalesPolicy
{
    public function companyId(User $user, string $permission = 'sales.view'): int
    {
        if ($user->company_id === null || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this sales operation.');
        }

        return $user->company_id;
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
