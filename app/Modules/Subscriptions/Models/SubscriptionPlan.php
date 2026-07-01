<?php

namespace App\Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'slug', 'name', 'description', 'monthly_price', 'annual_price', 'currency',
        'max_users', 'storage_gb', 'api_rate_limit_per_minute', 'trial_enabled',
        'trial_days', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'monthly_price' => 'decimal:2',
            'annual_price' => 'decimal:2',
            'is_active' => 'boolean',
            'trial_enabled' => 'boolean',
            'trial_days' => 'integer',
        ];
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(SubscriptionFeature::class, 'plan_feature')
            ->withPivot(['value', 'enabled'])
            ->withTimestamps();
    }

    public function companySubscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    public function promotions(): HasMany
    {
        return $this->hasMany(SubscriptionPlanPromotion::class);
    }
}
