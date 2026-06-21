<?php

namespace App\Modules\CustomModules\Models;

use App\Modules\Companies\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class CustomModule extends Model
{
    protected $fillable = [
        'slug', 'name', 'version', 'description', 'publisher',
        'minimum_erp_version', 'manifest', 'status', 'is_active',
    ];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'is_active' => 'boolean'];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_custom_modules')
            ->withPivot(['installed_version', 'status', 'settings', 'installed_by', 'installed_at', 'disabled_at'])
            ->withTimestamps();
    }
}
