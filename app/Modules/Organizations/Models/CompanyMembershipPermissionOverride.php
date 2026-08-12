<?php

namespace App\Modules\Organizations\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CompanyMembershipPermissionOverride extends Model
{
    public const EFFECT_ALLOW = 'allow';

    public const EFFECT_DENY = 'deny';

    protected $fillable = [
        'company_membership_id',
        'permission_name',
        'effect',
    ];

    public function companyMembership(): BelongsTo
    {
        return $this->belongsTo(CompanyMembership::class);
    }
}
