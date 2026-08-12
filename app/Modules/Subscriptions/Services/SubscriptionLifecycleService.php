<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Owns every state transition a subscription can make. Payment collection and
 * scheduling live elsewhere so this stays the single place where status,
 * billing period and company access are changed together.
 */
class SubscriptionLifecycleService
{
    public function __construct(
        private readonly SubscriptionNotifier $notifier,
        private readonly OrganizationEntitlementService $entitlements,
    ) {}

    /** Extend the paid period after a successful renewal charge. */
    public function applyRenewal(CompanySubscription $subscription, SubscriptionPayment $payment): CompanySubscription
    {
        return DB::transaction(function () use ($subscription, $payment) {
            $start = $payment->period_starts_at ?? $subscription->periodEndsAt() ?? now();
            $end = $payment->period_ends_at ?? $subscription->nextPeriodEndsAt();

            $subscription->forceFill([
                'status' => SubscriptionStatus::Active->value,
                'current_period_started_at' => $start,
                'current_period_ends_at' => $end,
                'grace_ends_at' => null,
                'ends_at' => null,
                'trial_ends_at' => $subscription->status === SubscriptionStatus::Trialing->value ? $subscription->trial_ends_at : null,
                'renewal_failures' => 0,
                'last_renewal_attempt_at' => now(),
                'last_renewed_at' => now(),
                'entitlements_snapshot' => $this->entitlements->snapshot($subscription->loadMissing('plan')->plan),
            ])->save();

            $this->restoreCompanyAccess($subscription);

            return $subscription->refresh();
        });
    }

    /**
     * A renewal did not go through. The company keeps working through the
     * grace window while retries and reminders run.
     */
    public function markPastDue(CompanySubscription $subscription, ?SubscriptionPayment $payment = null, ?string $reason = null): CompanySubscription
    {
        $graceEnds = $subscription->grace_ends_at
            ?? ($subscription->periodEndsAt() ?? now())->copy()->addDays($this->graceDays());

        $subscription->forceFill([
            'status' => SubscriptionStatus::PastDue->value,
            'grace_ends_at' => $graceEnds,
            'last_renewal_attempt_at' => now(),
            'metadata' => array_replace($subscription->metadata ?? [], array_filter([
                'past_due_reason' => $reason,
                'past_due_since' => now()->toISOString(),
            ])),
        ])->save();

        $this->notifier->pastDue($subscription->refresh(), $payment);

        return $subscription;
    }

    /** Grace ran out (or the customer cancelled at period end): cut access off. */
    public function expire(CompanySubscription $subscription, string $reason): CompanySubscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Expired->value,
                'auto_renew' => false,
                'ends_at' => now(),
                'cancellation_reason' => $subscription->cancellation_reason ?: $reason,
                'metadata' => array_replace($subscription->metadata ?? [], ['expired_reason' => $reason]),
            ])->save();

            $this->suspendCompany($subscription);
            $this->notifier->expired($subscription->refresh(), $reason);

            return $subscription;
        });
    }

    /** Stop the next renewal but let the customer use what they paid for. */
    public function cancelAtPeriodEnd(CompanySubscription $subscription, ?string $reason = null): CompanySubscription
    {
        $subscription->forceFill([
            'cancel_at_period_end' => true,
            'auto_renew' => false,
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
            'ends_at' => $subscription->accessEndsAt(),
        ])->save();

        $this->notifier->cancelled($subscription->refresh(), false);

        return $subscription;
    }

    public function cancelImmediately(CompanySubscription $subscription, ?string $reason = null): CompanySubscription
    {
        return DB::transaction(function () use ($subscription, $reason) {
            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled->value,
                'cancel_at_period_end' => false,
                'auto_renew' => false,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
                'ends_at' => now(),
                'grace_ends_at' => null,
            ])->save();

            $this->suspendCompany($subscription);
            $this->notifier->cancelled($subscription->refresh(), true);

            return $subscription;
        });
    }

    /** Undo a scheduled cancellation while the period is still running. */
    public function resume(CompanySubscription $subscription): CompanySubscription
    {
        $subscription->forceFill([
            'cancel_at_period_end' => false,
            'auto_renew' => true,
            'cancelled_at' => null,
            'cancellation_reason' => null,
            'ends_at' => null,
        ])->save();

        $this->notifier->resumed($subscription->refresh());

        return $subscription;
    }

    public function setAutoRenew(CompanySubscription $subscription, bool $autoRenew): CompanySubscription
    {
        $subscription->forceFill([
            'auto_renew' => $autoRenew,
            'cancel_at_period_end' => $autoRenew ? false : $subscription->cancel_at_period_end,
        ])->save();

        return $subscription->refresh();
    }

    public function storePaymentMethod(CompanySubscription $subscription, string $token, ?string $brand = null, ?string $last4 = null): CompanySubscription
    {
        $subscription->forceFill([
            'payment_method_token' => $token,
            'payment_method_brand' => $brand,
            'payment_method_last4' => $last4,
        ])->save();

        return $subscription->refresh();
    }

    public function forgetPaymentMethod(CompanySubscription $subscription): CompanySubscription
    {
        $subscription->forceFill([
            'payment_method_token' => null,
            'payment_method_brand' => null,
            'payment_method_last4' => null,
        ])->save();

        return $subscription->refresh();
    }

    public function recordRenewalFailure(CompanySubscription $subscription): CompanySubscription
    {
        $subscription->forceFill([
            'renewal_failures' => (int) $subscription->renewal_failures + 1,
            'last_renewal_attempt_at' => now(),
        ])->save();

        return $subscription->refresh();
    }

    public function graceDays(): int
    {
        return max((int) config('subscriptions.grace_days', 5), 0);
    }

    public function graceEndsAt(CompanySubscription $subscription): Carbon
    {
        return ($subscription->periodEndsAt() ?? now())->copy()->addDays($this->graceDays());
    }

    /** Lift a billing suspension once a subscription is serving again. */
    public function restoreAccess(CompanySubscription $subscription): void
    {
        $this->restoreCompanyAccess($subscription);
    }

    private function restoreCompanyAccess(CompanySubscription $subscription): void
    {
        if ($subscription->organization_id !== null) {
            Organization::query()
                ->whereKey($subscription->organization_id)
                ->whereNotNull('billing_suspended_at')
                ->update(['billing_suspended_at' => null]);

            return;
        }

        $company = $subscription->company ?? Company::find($subscription->company_id);

        if ($company && (! $company->is_active || $company->plan !== $subscription->plan?->slug)) {
            $settings = (array) ($company->settings ?? []);
            unset($settings['billing']['suspended_at']);

            $company->forceFill(array_filter([
                'is_active' => true,
                'plan' => $subscription->loadMissing('plan')->plan?->slug,
                'settings' => $settings,
            ], fn ($value) => $value !== null))->save();
        }
    }

    private function suspendCompany(CompanySubscription $subscription): void
    {
        if ($subscription->organization_id !== null) {
            $hasOtherServing = CompanySubscription::query()
                ->where('organization_id', $subscription->organization_id)
                ->whereKeyNot($subscription->id)
                ->whereIn('status', SubscriptionStatus::servingValues())
                ->exists();

            if (! $hasOtherServing) {
                Organization::query()
                    ->whereKey($subscription->organization_id)
                    ->update(['billing_suspended_at' => now()]);
            }

            return;
        }

        $company = $subscription->company ?? Company::find($subscription->company_id);

        // Only suspend when nothing else is keeping the company alive, e.g. a
        // replacement subscription created by an upgrade.
        $hasOtherServing = $company?->subscriptions()
            ->whereKeyNot($subscription->id)
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->exists();

        if ($company && ! $hasOtherServing) {
            $settings = (array) ($company->settings ?? []);
            // Flag the reason so the admins can still sign in to pay.
            $settings['billing']['suspended_at'] = now()->toISOString();

            $company->forceFill(['is_active' => false, 'settings' => $settings])->save();
        }
    }
}
