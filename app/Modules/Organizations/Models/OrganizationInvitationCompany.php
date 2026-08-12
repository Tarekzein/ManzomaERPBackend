<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Authentication\Models\CompanyCustomRole;
use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Models\Role;

class OrganizationInvitationCompany extends Model
{
    protected $fillable = [
        'organization_id',
        'organization_invitation_id',
        'company_id',
        'role_id',
        'custom_role_id',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function invitation(): BelongsTo
    {
        return $this->belongsTo(OrganizationInvitation::class, 'organization_invitation_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CompanyCustomRole::class, 'custom_role_id');
    }
}
