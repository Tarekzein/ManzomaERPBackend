<?php

namespace App\Modules\Inventory\Policies;

use App\Modules\Authentication\Models\User;
use App\Modules\Platform\Services\CompanyContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class InventoryPolicy
{
    public function __construct(private readonly CompanyContext $context) {}

    public function companyId(User $user, string $permission = 'inventory.view'): int
    {
        $companyId = $this->context->companyIdFor($user);

        if ($companyId === null || ! $user->can($permission)) {
            throw new AuthorizationException('You are not allowed to perform this inventory operation.');
        }

        return $companyId;
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
