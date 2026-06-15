<?php

use App\Modules\Authentication\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('companies.{companyId}.reporting', function (User $user, int $companyId) {
    return $user->can('reporting.view') && ($user->isSuperAdmin() || (int) $user->company_id === $companyId);
});
