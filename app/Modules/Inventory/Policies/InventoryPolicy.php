<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class InventoryPolicy
{
    public function companyId(User $user, string $permission = 'inventory.view'): int
    {
        if ($user->company_id === null || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this inventory operation.');
        }

        return $user->company_id;
    }

    public function ensureOwned(User $user, Model $model, string $permission = 'inventory.edit'): int
    {
        $companyId = $this->companyId($user, $permission);
        if ((int) $model->getAttribute('company_id') !== $companyId) {
            throw new AuthorizationException('This inventory record belongs to another company.');
        }

        return $companyId;
    }
}
