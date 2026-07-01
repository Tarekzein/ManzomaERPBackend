<?php

namespace App\Modules\Subscriptions\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanPromotion extends Model
{
    use HasFactory;

    public const TYPE_PERCENT = 'percent';
    public const TYPE_FIXED = 'fixed';

    public const CYCLE_MONTHLY = 'monthly';
    public const CYCLE_ANNUAL = 'annual';
    public const CYCLE_BOTH = 'both';

    protected $fillable = [
        'subscription_plan_id',
        'name',
        'discount_type',
        'discount_value',
        'billing_cycle',
        'starts_at',
        'ends_at',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function appliesToCycle(string $billingCycle): bool
    {
        return $this->billing_cycle === self::CYCLE_BOTH || $this->billing_cycle === $billingCycle;
    }

    public function isRunning(): bool
    {
        return $this->is_active && now()->betweenIncluded($this->starts_at, $this->ends_at);
    }
}
