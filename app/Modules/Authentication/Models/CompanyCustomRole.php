<?php

namespace App\Modules\Authentication\Models;

use App\Modules\Companies\Models\Company;
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
}
