<?php

namespace App\Modules\Subscriptions\Policies;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

class SubscriptionPolicy
{
    public function ensureCanManageCatalog(User $user): void
    {
        if (! $user->isSuperAdmin()) {
            throw new AuthorizationException('Only a super admin can manage subscription plans and features.');
        }
    }

    public function ensureCanSubscribe(User $user): void
    {
        if ($user->company_id === null || ! $user->hasRole(UserRole::CompanyAdmin->value)) {
            throw new AuthorizationException('Only a company admin can manage the company subscription.');
        }
    }
}
