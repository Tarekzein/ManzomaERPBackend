<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class SubscriptionFeature extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'module',
        'description',
        'is_metered',
    ];

    protected function casts(): array
    {
        return [
            'is_metered' => 'boolean',
        ];
    }

    public function plans(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionPlan::class, 'plan_feature')
            ->withPivot(['value', 'enabled'])
            ->withTimestamps();
    }
}
