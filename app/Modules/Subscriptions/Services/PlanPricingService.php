<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Models\SubscriptionPlanPromotion;
use Illuminate\Support\Collection;

class PlanPricingService
{
    public function decorate(SubscriptionPlan $plan): SubscriptionPlan
    {
        $plan->setAttribute('pricing', [
            'monthly' => $this->forCycle($plan, SubscriptionPlanPromotion::CYCLE_MONTHLY),
            'annual' => $this->forCycle($plan, SubscriptionPlanPromotion::CYCLE_ANNUAL),
        ]);
        $plan->setAttribute('trial', [
            'enabled' => (bool) $plan->trial_enabled && (int) $plan->trial_days > 0,
            'days' => (int) $plan->trial_days,
        ]);
        $plan->makeHidden('promotions');

        return $plan;
    }

    public function decorateMany(Collection $plans): Collection
    {
        return $plans->map(fn (SubscriptionPlan $plan) => $this->decorate($plan));
    }

    public function forCycle(SubscriptionPlan $plan, string $billingCycle): array
    {
        $baseAmount = (float) ($billingCycle === SubscriptionPlanPromotion::CYCLE_ANNUAL
            ? $plan->annual_price
            : $plan->monthly_price);
        $promotion = $this->activePromotion($plan, $billingCycle);
        $discountAmount = $promotion ? $this->discountAmount($baseAmount, $promotion) : 0.0;
        $finalAmount = max(0, $baseAmount - $discountAmount);

        return [
            'base_amount' => round($baseAmount, 2),
            'discount_amount' => round($discountAmount, 2),
            'final_amount' => round($finalAmount, 2),
            'promotion' => $promotion ? $this->promotionPayload($promotion) : null,
        ];
    }

    public function activePromotion(SubscriptionPlan $plan, string $billingCycle): ?SubscriptionPlanPromotion
    {
        $plan->loadMissing('promotions');

        return $plan->promotions
            ->filter(fn (SubscriptionPlanPromotion $promotion) => $promotion->isRunning() && $promotion->appliesToCycle($billingCycle))
            ->sortByDesc('discount_value')
            ->first();
    }

    private function discountAmount(float $baseAmount, SubscriptionPlanPromotion $promotion): float
    {
        if ($promotion->discount_type === SubscriptionPlanPromotion::TYPE_PERCENT) {
            return min($baseAmount, $baseAmount * ((float) $promotion->discount_value / 100));
        }

        return min($baseAmount, (float) $promotion->discount_value);
    }

    private function promotionPayload(SubscriptionPlanPromotion $promotion): array
    {
        return [
            'id' => $promotion->id,
            'name' => $promotion->name,
            'discount_type' => $promotion->discount_type,
            'discount_value' => (float) $promotion->discount_value,
            'billing_cycle' => $promotion->billing_cycle,
            'starts_at' => $promotion->starts_at?->toISOString(),
            'ends_at' => $promotion->ends_at?->toISOString(),
        ];
    }
}
