<?php

namespace App\Modules\Subscriptions\Http\Requests;

use App\Modules\Subscriptions\Models\SubscriptionPlanPromotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SavePlanPromotionRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'discount_type' => ['required', Rule::in([SubscriptionPlanPromotion::TYPE_PERCENT, SubscriptionPlanPromotion::TYPE_FIXED])],
            'discount_value' => ['required', 'numeric', 'gt:0'],
            'billing_cycle' => ['required', Rule::in([
                SubscriptionPlanPromotion::CYCLE_MONTHLY,
                SubscriptionPlanPromotion::CYCLE_ANNUAL,
                SubscriptionPlanPromotion::CYCLE_BOTH,
            ])],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'is_active' => ['required', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                $data = $this->only(['discount_type', 'discount_value', 'billing_cycle', 'starts_at', 'ends_at', 'is_active']);
                if (($data['discount_type'] ?? null) === SubscriptionPlanPromotion::TYPE_PERCENT
                    && (float) ($data['discount_value'] ?? 0) > 100) {
                    $validator->errors()->add('discount_value', 'Percentage discounts cannot exceed 100%.');
                }

                if (! ($data['is_active'] ?? false)) {
                    return;
                }

                if (empty($data['billing_cycle']) || empty($data['starts_at']) || empty($data['ends_at'])) {
                    return;
                }

                $plan = $this->route('plan');
                $promotion = $this->route('promotion');
                $cycles = $this->cyclesCoveredBy($data['billing_cycle']);

                $overlap = SubscriptionPlanPromotion::query()
                    ->where('subscription_plan_id', $plan->id)
                    ->where('is_active', true)
                    ->when($promotion, fn ($query) => $query->whereKeyNot($promotion->id))
                    ->whereIn('billing_cycle', $cycles)
                    ->where('starts_at', '<', $data['ends_at'])
                    ->where('ends_at', '>', $data['starts_at'])
                    ->exists();

                if ($overlap) {
                    $validator->errors()->add('starts_at', 'This promotion overlaps another active promotion for the same billing cycle.');
                }
            },
        ];
    }

    private function cyclesCoveredBy(string $billingCycle): array
    {
        return match ($billingCycle) {
            SubscriptionPlanPromotion::CYCLE_MONTHLY => [SubscriptionPlanPromotion::CYCLE_MONTHLY, SubscriptionPlanPromotion::CYCLE_BOTH],
            SubscriptionPlanPromotion::CYCLE_ANNUAL => [SubscriptionPlanPromotion::CYCLE_ANNUAL, SubscriptionPlanPromotion::CYCLE_BOTH],
            default => [
                SubscriptionPlanPromotion::CYCLE_MONTHLY,
                SubscriptionPlanPromotion::CYCLE_ANNUAL,
                SubscriptionPlanPromotion::CYCLE_BOTH,
            ],
        };
    }
}
