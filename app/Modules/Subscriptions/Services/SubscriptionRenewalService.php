<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\PaymobException;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Drives the recurring side of billing: charge saved cards when a period is
 * about to end, fall back to a hosted checkout link when there is no card,
 * keep failed renewals in a grace window, and expire what stays unpaid.
 */
class SubscriptionRenewalService
{
    public function __construct(
        private readonly PaymobGateway $gateway,
        private readonly SubscriptionPaymentService $payments,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionNotifier $notifier,
    ) {}

    /**
     * @return array<string, int> counters keyed by the action taken
     */
    public function processDue(?int $limit = null): array
    {
        $stats = ['renewed' => 0, 'manual_review' => 0, 'charge_failed' => 0, 'checkout_sent' => 0, 'past_due' => 0, 'expired' => 0, 'cancelled' => 0, 'skipped' => 0, 'errors' => 0];

        $this->dueQuery()
            ->when($limit, fn (Builder $query) => $query->limit($limit))
            ->with('plan', 'company')
            ->orderBy('current_period_ends_at')
            ->chunkById(100, function ($subscriptions) use (&$stats) {
                foreach ($subscriptions as $subscription) {
                    try {
                        $outcome = $this->process($subscription);
                        $stats[$outcome] = ($stats[$outcome] ?? 0) + 1;
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        Log::error('[subscriptions] renewal processing failed', [
                            'subscription_id' => $subscription->id,
                            'company_id' => $subscription->company_id,
                            'organization_id' => $subscription->organization_id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /** Subscriptions whose period ends inside the lookahead window, or already has. */
    public function dueQuery(): Builder
    {
        $window = now()->addHours(max((int) config('subscriptions.renewal_window_hours', 72), 1));

        return CompanySubscription::query()
            ->whereIn('status', SubscriptionStatus::renewableValues())
            ->whereNotNull('current_period_ends_at')
            ->where(function (Builder $query) use ($window) {
                $query->where('current_period_ends_at', '<=', $window)
                    ->orWhere(fn (Builder $inner) => $inner
                        ->where('status', SubscriptionStatus::PastDue->value)
                        ->whereNotNull('grace_ends_at'));
            });
    }

    /**
     * @return string one of the keys used in the stats array
     */
    public function process(CompanySubscription $subscription): string
    {
        return DB::transaction(function () use ($subscription) {
            if ($subscription->organization_id !== null) {
                Organization::query()
                    ->whereKey($subscription->organization_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            $locked = CompanySubscription::query()
                ->with('plan', 'company')
                ->whereKey($subscription->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            return $this->processLocked($locked);
        });
    }

    private function processLocked(CompanySubscription $subscription): string
    {
        if (! in_array($subscription->status, SubscriptionStatus::renewableValues(), true)) {
            return 'skipped';
        }

        $periodEnd = $subscription->periodEndsAt();

        if (! $periodEnd) {
            return 'skipped';
        }

        // Grace ran out: the account stops here.
        if ($subscription->status === SubscriptionStatus::PastDue->value
            && $subscription->grace_ends_at
            && $subscription->grace_ends_at->isPast()) {
            $this->lifecycle->expire($subscription, 'grace_period_elapsed');

            return 'expired';
        }

        // Cancelled at period end: let the paid period run out, then stop.
        if ($subscription->cancel_at_period_end) {
            if ($periodEnd->isPast()) {
                $this->lifecycle->expire($subscription, 'cancelled_at_period_end');

                return 'cancelled';
            }

            return 'skipped';
        }

        if ($periodEnd->isFuture() && ! $this->insideRenewalWindow($periodEnd)) {
            return 'skipped';
        }

        $periodEnded = $periodEnd->isPast();
        $autoCharge = $subscription->auto_renew
            && $subscription->hasSavedCard()
            && $this->gateway->supportsSavedCardCharges();

        // A card on file is charged on the renewal date itself, never early.
        if (! $periodEnded && $autoCharge) {
            return 'skipped';
        }

        $payment = $this->payments->createRenewalPayment($subscription);

        // `activation_pending` and `requires_review` both mean the provider
        // already captured money. Never charge or open checkout for them again.
        if ($payment->isSettled()) {
            return 'skipped';
        }

        if ($autoCharge && $this->canChargeSavedCard($payment)) {
            return $this->chargeSavedCard($subscription, $payment);
        }

        return $this->requestManualPayment($subscription, $payment, $periodEnded);
    }

    private function chargeSavedCard(CompanySubscription $subscription, SubscriptionPayment $payment): string
    {
        $payment->forceFill([
            'attempts' => (int) $payment->attempts + 1,
            'next_retry_at' => $this->payments->nextRetryAt((int) $payment->attempts + 1),
        ])->save();

        try {
            $result = $this->gateway->chargeSavedCard($payment, (string) $subscription->payment_method_token);
        } catch (PaymobException $exception) {
            Log::error('[paymob] saved-card renewal charge failed', [
                'subscription_id' => $subscription->id,
                'reference' => $payment->reference,
                'message' => $exception->getMessage(),
            ]);

            $result = ['status' => 'failed', 'message' => $exception->getMessage(), 'provider_order_id' => null, 'provider_transaction_id' => null, 'raw' => []];
        }

        $payment->forceFill([
            'provider_order_id' => $result['provider_order_id'] ?: $payment->provider_order_id,
            'provider_transaction_id' => $result['provider_transaction_id'] ?: $payment->provider_transaction_id,
            'metadata' => array_replace($payment->metadata ?? [], ['charge_result' => Str::limit(json_encode($result['raw'] ?? []), 2000, '')]),
        ])->save();

        if ($result['status'] === 'succeeded') {
            $settled = $this->payments->markSucceeded($payment->refresh(), ['renewal' => 'saved_card']);

            return $settled['payment']->isSuccessful() ? 'renewed' : 'manual_review';
        }

        // A pending 3-D Secure result is settled by the webhook instead.
        if ($result['status'] === 'pending') {
            return 'skipped';
        }

        $this->lifecycle->recordRenewalFailure($subscription);
        $reason = $result['message'] ?? 'The saved card was declined.';
        $payment->forceFill(['failure_reason' => Str::limit($reason, 250, '')])->save();

        // Out of retries: stop charging and let the customer pay by hand
        // against the same open invoice.
        if ($this->attemptsExhausted($payment)) {
            $payment = $this->payments->openCheckout($payment);
        }

        $this->notifier->paymentFailed($subscription->refresh(), $payment->refresh(), $reason);

        if ($subscription->periodEndsAt()?->isPast() && $subscription->status !== SubscriptionStatus::PastDue->value) {
            $this->lifecycle->markPastDue($subscription->refresh(), $payment, $reason);
        }

        return 'charge_failed';
    }

    /**
     * No saved card (or it is unusable): hand the customer a checkout link and
     * start the grace window once the period has actually run out.
     */
    private function requestManualPayment(CompanySubscription $subscription, SubscriptionPayment $payment, bool $periodEnded): string
    {
        $payment = $this->payments->openCheckout($payment);

        $reminderKey = 'checkout:'.$payment->reference;

        if (! $subscription->reminderWasSent($reminderKey) && $payment->checkout_url) {
            $this->notifier->actionRequired($subscription, $payment);
            $subscription->markReminderSent($reminderKey);
        }

        if ($periodEnded && $subscription->status !== SubscriptionStatus::PastDue->value) {
            $this->lifecycle->markPastDue($subscription->refresh(), $payment, 'renewal_not_paid');

            return 'past_due';
        }

        return 'checkout_sent';
    }

    /** Retry pacing: attempts are capped and spaced by the configured interval. */
    private function canChargeSavedCard(SubscriptionPayment $payment): bool
    {
        if ($this->attemptsExhausted($payment)) {
            return false;
        }

        return $payment->next_retry_at === null || $payment->next_retry_at->isPast();
    }

    private function attemptsExhausted(SubscriptionPayment $payment): bool
    {
        return (int) $payment->attempts >= max((int) config('subscriptions.retry.max_attempts', 3), 1);
    }

    private function insideRenewalWindow(Carbon $periodEnd): bool
    {
        return $periodEnd->lte(now()->addHours(max((int) config('subscriptions.renewal_window_hours', 72), 1)));
    }
}
