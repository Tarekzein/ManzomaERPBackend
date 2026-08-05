<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Sends the billing reminders that lead up to a renewal, a trial conversion or
 * the end of a grace window. Every reminder is de-duplicated against the
 * subscription so a re-run of the command never double-sends.
 */
class SubscriptionReminderService
{
    public function __construct(private readonly SubscriptionNotifier $notifier) {}

    /**
     * @return array<string, int>
     */
    public function send(): array
    {
        $stats = ['renewal' => 0, 'trial' => 0, 'past_due' => 0, 'errors' => 0];

        CompanySubscription::query()
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->with('plan')
            ->chunkById(200, function ($subscriptions) use (&$stats) {
                foreach ($subscriptions as $subscription) {
                    try {
                        $sent = $this->sendFor($subscription);

                        foreach ($sent as $kind) {
                            $stats[$kind] = ($stats[$kind] ?? 0) + 1;
                        }
                    } catch (\Throwable $exception) {
                        $stats['errors']++;
                        Log::error('[subscriptions] reminder failed', [
                            'subscription_id' => $subscription->id,
                            'message' => $exception->getMessage(),
                        ]);
                    }
                }
            });

        return $stats;
    }

    /**
     * @return array<int, string> kinds of reminder that were sent
     */
    public function sendFor(CompanySubscription $subscription): array
    {
        if ($subscription->status === SubscriptionStatus::PastDue->value) {
            return $this->pastDueReminder($subscription) ? ['past_due'] : [];
        }

        if ($subscription->isOnTrial()) {
            return $this->trialReminder($subscription) ? ['trial'] : [];
        }

        return $this->renewalReminder($subscription) ? ['renewal'] : [];
    }

    private function renewalReminder(CompanySubscription $subscription): bool
    {
        $periodEnd = $subscription->periodEndsAt();

        if (! $periodEnd || $periodEnd->isPast()) {
            return false;
        }

        $daysLeft = $this->daysUntil($periodEnd);
        $milestones = (array) config('subscriptions.reminders.renewal_days', [7, 3, 1]);

        if (! in_array($daysLeft, array_map('intval', $milestones), true)) {
            return false;
        }

        $key = "renewal:{$periodEnd->toDateString()}:{$daysLeft}";

        if ($subscription->reminderWasSent($key)) {
            return false;
        }

        // A subscription that will not renew gets a different message: it is
        // ending, not being charged.
        if ($subscription->cancel_at_period_end) {
            $this->notifier->cancelled($subscription, false);
        } else {
            $this->notifier->renewalUpcoming($subscription, $daysLeft, $this->pendingCheckoutUrl($subscription));
        }

        $subscription->markReminderSent($key);

        return true;
    }

    private function trialReminder(CompanySubscription $subscription): bool
    {
        $trialEnd = $subscription->trial_ends_at;

        if (! $trialEnd || $trialEnd->isPast()) {
            return false;
        }

        $daysLeft = $this->daysUntil($trialEnd);
        $milestones = array_map('intval', (array) config('subscriptions.reminders.trial_days', [3, 1]));

        if (! in_array($daysLeft, $milestones, true)) {
            return false;
        }

        $key = "trial:{$trialEnd->toDateString()}:{$daysLeft}";

        if ($subscription->reminderWasSent($key)) {
            return false;
        }

        $this->notifier->trialEnding($subscription, $daysLeft, $this->pendingCheckoutUrl($subscription));
        $subscription->markReminderSent($key);

        return true;
    }

    private function pastDueReminder(CompanySubscription $subscription): bool
    {
        if (! config('subscriptions.reminders.past_due_daily', true)) {
            return false;
        }

        $key = 'past_due:'.now()->toDateString();

        if ($subscription->reminderWasSent($key)) {
            return false;
        }

        $this->notifier->pastDue($subscription, $this->pendingPayment($subscription));
        $subscription->markReminderSent($key);

        return true;
    }

    private function pendingPayment(CompanySubscription $subscription): ?SubscriptionPayment
    {
        return SubscriptionPayment::query()
            ->where('company_subscription_id', $subscription->id)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->latest('id')
            ->first();
    }

    private function pendingCheckoutUrl(CompanySubscription $subscription): ?string
    {
        return $this->pendingPayment($subscription)?->checkout_url;
    }

    /**
     * Calendar days away, not 24-hour blocks: a renewal late tomorrow is "1
     * day" so the milestone list reads the way a customer would expect.
     */
    private function daysUntil(Carbon $moment): int
    {
        return (int) round(now()->startOfDay()->diffInDays($moment->copy()->startOfDay(), false));
    }
}
