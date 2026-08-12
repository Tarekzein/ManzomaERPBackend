<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Support\Collection;

/**
 * Billing notices always go to the company admins, who are the only people who
 * can act on a payment problem.
 */
class SubscriptionNotifier
{
    private const ACTION_URL = '/subscriptions';

    public function __construct(private readonly NotificationService $notifications) {}

    public function renewalUpcoming(CompanySubscription $subscription, int $daysLeft, ?string $checkoutUrl = null): void
    {
        $plan = $subscription->plan?->name ?? 'your plan';
        $when = $daysLeft <= 1 ? 'tomorrow' : "in {$daysLeft} days";
        $amount = $this->amount($subscription);

        $this->push(
            $subscription,
            'subscription.renewal.upcoming',
            'Subscription renews '.$when,
            $subscription->hasSavedCard()
                ? "{$plan} renews {$when} and {$amount} will be charged to your saved card."
                : "{$plan} renews {$when}. Complete the {$amount} payment to keep your account active.",
            ['days_left' => $daysLeft, 'checkout_url' => $checkoutUrl],
            'info',
        );
    }

    public function trialEnding(CompanySubscription $subscription, int $daysLeft, ?string $checkoutUrl = null): void
    {
        $when = $daysLeft <= 1 ? 'tomorrow' : "in {$daysLeft} days";

        $this->push(
            $subscription,
            'subscription.trial.ending',
            'Trial ends '.$when,
            "Your {$this->planName($subscription)} trial ends {$when}. Add a payment to continue without interruption.",
            ['days_left' => $daysLeft, 'checkout_url' => $checkoutUrl],
            'warning',
        );
    }

    public function actionRequired(CompanySubscription $subscription, SubscriptionPayment $payment): void
    {
        $this->push(
            $subscription,
            'subscription.renewal.upcoming',
            'Payment required to renew',
            "{$this->planName($subscription)} is due for renewal. Pay {$this->money($payment->currency, (float) $payment->amount)} to keep your account active.",
            ['reference' => $payment->reference, 'checkout_url' => $payment->checkout_url],
            'warning',
        );
    }

    public function paymentSucceeded(CompanySubscription $subscription, SubscriptionPayment $payment): void
    {
        $this->push(
            $subscription,
            'subscription.payment.succeeded',
            'Subscription payment received',
            "{$this->money($payment->currency, (float) $payment->amount)} was received. Your plan is active until "
                .($subscription->current_period_ends_at?->toDayDateTimeString() ?? 'the end of the period').'.',
            ['reference' => $payment->reference, 'paid_at' => $payment->paid_at?->toISOString()],
            'info',
        );
    }

    public function paymentFailed(CompanySubscription $subscription, SubscriptionPayment $payment, ?string $reason = null): void
    {
        $graceEnds = $subscription->grace_ends_at?->toFormattedDateString();

        $this->push(
            $subscription,
            'subscription.payment.failed',
            'Subscription payment failed',
            trim("We could not charge {$this->money($payment->currency, (float) $payment->amount)} for {$this->planName($subscription)}. "
                .($reason ? "Reason: {$reason}. " : '')
                .($graceEnds ? "Update your payment method before {$graceEnds} to avoid losing access." : 'Please update your payment method.')),
            ['reference' => $payment->reference, 'checkout_url' => $payment->checkout_url, 'attempts' => $payment->attempts],
            'critical',
        );
    }

    public function pastDue(CompanySubscription $subscription, ?SubscriptionPayment $payment = null): void
    {
        $graceEnds = $subscription->grace_ends_at?->toFormattedDateString();

        $this->push(
            $subscription,
            'subscription.past_due',
            'Subscription payment overdue',
            "{$this->planName($subscription)} is overdue."
                .($graceEnds ? " Access continues until {$graceEnds}." : ''),
            ['reference' => $payment?->reference, 'checkout_url' => $payment?->checkout_url],
            'critical',
        );
    }

    public function cancelled(CompanySubscription $subscription, bool $immediately): void
    {
        $endsAt = $subscription->accessEndsAt()?->toFormattedDateString();

        $this->push(
            $subscription,
            'subscription.cancelled',
            'Subscription cancelled',
            $immediately
                ? "{$this->planName($subscription)} was cancelled and access has ended."
                : "{$this->planName($subscription)} will not renew.".($endsAt ? " Access continues until {$endsAt}." : ''),
            ['immediately' => $immediately, 'ends_at' => $subscription->accessEndsAt()?->toISOString()],
            $immediately ? 'critical' : 'warning',
        );
    }

    public function resumed(CompanySubscription $subscription): void
    {
        $this->push(
            $subscription,
            'subscription.cancelled',
            'Subscription resumed',
            "{$this->planName($subscription)} will renew again on "
                .($subscription->current_period_ends_at?->toFormattedDateString() ?? 'the next billing date').'.',
            ['auto_renew' => $subscription->auto_renew],
            'info',
        );
    }

    public function expired(CompanySubscription $subscription, string $reason): void
    {
        $this->push(
            $subscription,
            'subscription.expired',
            'Subscription expired',
            "{$this->planName($subscription)} expired and the account is now suspended. Renew to restore access.",
            ['reason' => $reason],
            'critical',
        );
    }

    private function push(CompanySubscription $subscription, string $event, string $title, string $message, array $payload, string $severity): void
    {
        $recipients = $this->recipients($subscription);

        if ($recipients->isEmpty()) {
            return;
        }

        $this->notifications->send(
            $recipients,
            $event,
            $title,
            $message,
            $payload + ['subscription_id' => $subscription->id, 'plan' => $this->planName($subscription)],
            self::ACTION_URL,
            $severity,
        );
    }

    private function recipients(CompanySubscription $subscription): Collection
    {
        if ($subscription->organization_id !== null) {
            $billingRecipients = User::query()
                ->where('users.is_active', true)
                ->join('organization_memberships', 'organization_memberships.user_id', '=', 'users.id')
                ->where('organization_memberships.organization_id', $subscription->organization_id)
                ->where('organization_memberships.status', 'active')
                ->whereIn('organization_memberships.role', ['owner', 'billing_admin'])
                ->orderBy('users.id')
                ->select('users.*')
                ->distinct()
                ->get();

            if ($billingRecipients->isNotEmpty()) {
                return $billingRecipients;
            }
        }

        $admins = User::query()
            ->where('company_id', $subscription->company_id)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::CompanyAdmin->value))
            ->get();

        return $admins->isNotEmpty()
            ? $admins
            : User::query()->where('company_id', $subscription->company_id)->where('is_active', true)->limit(1)->get();
    }

    private function planName(CompanySubscription $subscription): string
    {
        return $subscription->loadMissing('plan')->plan?->name ?? 'Your subscription';
    }

    private function amount(CompanySubscription $subscription): string
    {
        $plan = $subscription->loadMissing('plan')->plan;
        $amount = $plan
            ? (float) ($subscription->billing_cycle === 'annual' ? $plan->annual_price : $plan->monthly_price)
            : 0.0;

        return $this->money($plan?->currency ?? config('services.paymob.currency', 'EGP'), $amount);
    }

    private function money(?string $currency, float $amount): string
    {
        return trim(($currency ?: '').' '.number_format($amount, 2));
    }
}
