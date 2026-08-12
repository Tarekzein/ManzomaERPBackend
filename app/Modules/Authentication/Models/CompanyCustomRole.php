<?php

namespace App\Modules\Authentication\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\CompanyMembership;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyCustomRole extends Model
{
    protected $fillable = ['company_id', 'name', 'description', 'permissions'];

    protected function casts(): array
    {
        return ['permissions' => 'array'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'custom_role_id');
    }

    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class, 'custom_role_id');
    }
}
