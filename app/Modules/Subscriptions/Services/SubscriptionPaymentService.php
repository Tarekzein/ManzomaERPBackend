<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Enums\PaymentPurpose;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Exceptions\OrganizationQuotaExceededException;
use App\Modules\Subscriptions\Exceptions\PaymobException;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Support\BillingPeriod;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class SubscriptionPaymentService
{
    public function __construct(
        private readonly PaymobGateway $gateway,
        private readonly CompanySubscriptionService $subscriptions,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly SubscriptionNotifier $notifier,
        private readonly FinanceSetupService $financeSetup,
        private readonly InventorySetupService $inventorySetup,
        private readonly PlanPricingService $pricing,
    ) {}

    /* ---------------------------------------------------------------------
     | Creating payments
     |--------------------------------------------------------------------- */

    public function createRegistrationPayment(User $admin, SubscriptionPlan $plan, string $billingCycle, string $plainToken): SubscriptionPayment
    {
        $payment = $this->newPayment(
            company: $admin->company,
            user: $admin,
            plan: $plan,
            billingCycle: $billingCycle,
            purpose: PaymentPurpose::Registration,
            attributes: ['registration_token_hash' => hash('sha256', $plainToken)],
            metadata: ['source' => 'registration'],
        );

        return $this->openCheckout($payment)->load('company', 'user.roles.permissions', 'plan.features');
    }

    /**
     * Checkout started from inside the app: a plan change, a cycle change, or
     * paying a renewal by hand.
     */
    public function createUpgradeCheckout(User $actor, SubscriptionPlan $plan, string $billingCycle): SubscriptionPayment
    {
        $this->subscriptions->ensureCanManageBilling($actor);
        $company = $this->subscriptions->companyFor($actor);
        $this->subscriptions->ensurePlanFits($company, $plan);
        $subscription = $this->subscriptions->current($actor);

        // Asking for the same plan again reuses the open invoice, so a customer
        // who clicks twice cannot end up with two payable Paymob orders.
        $open = SubscriptionPayment::query()
            ->when(
                $company->organization_id !== null,
                fn ($query) => $query->where('organization_id', $company->organization_id),
                fn ($query) => $query->where('company_id', $company->getKey()),
            )
            ->where('purpose', PaymentPurpose::Upgrade->value)
            ->where('subscription_plan_id', $plan->id)
            ->where('billing_cycle', $billingCycle)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->latest('id')
            ->first();

        if ($open) {
            return $this->openCheckout($open)->load('company', 'plan.features');
        }

        $payment = $this->newPayment(
            company: $company,
            user: $actor,
            plan: $plan,
            billingCycle: $billingCycle,
            purpose: PaymentPurpose::Upgrade,
            attributes: ['company_subscription_id' => $subscription?->id],
            metadata: [
                'source' => 'in_app',
                'requested_by_user_id' => $actor->id,
                'previous_plan' => $subscription?->plan?->slug,
            ],
        );

        return $this->openCheckout($payment)->load('company', 'plan.features');
    }

    /**
     * The charge that extends the current period. Idempotent per billing
     * period so the scheduler can run as often as it likes.
     */
    public function createRenewalPayment(CompanySubscription $subscription): SubscriptionPayment
    {
        $subscription->loadMissing('plan', 'company');
        $periodStart = $subscription->periodEndsAt() ?? now();
        $periodEnd = BillingPeriod::nextEnd($subscription->billing_cycle, $periodStart);
        $periodKey = $subscription->billingPeriodKey($periodEnd);

        $existing = SubscriptionPayment::query()
            ->where('company_subscription_id', $subscription->id)
            ->where('billing_period_key', $periodKey)
            ->latest('id')
            ->first();

        if ($existing) {
            // One invoice per period: a previously declined attempt is reopened
            // rather than duplicated, so the customer only ever pays once.
            if ($existing->status === SubscriptionPayment::STATUS_FAILED) {
                $existing->forceFill([
                    'status' => SubscriptionPayment::STATUS_PENDING,
                    'failed_at' => null,
                ])->save();
            }

            return $existing->refresh();
        }

        try {
            return $this->newPayment(
                company: $subscription->company,
                user: $this->billingContact($subscription),
                plan: $subscription->plan,
                billingCycle: $subscription->billing_cycle,
                purpose: PaymentPurpose::Renewal,
                attributes: [
                    'company_subscription_id' => $subscription->id,
                    'billing_period_key' => $periodKey,
                    'period_starts_at' => $periodStart->isPast() ? now() : $periodStart,
                    'period_ends_at' => $periodEnd,
                ],
                metadata: ['source' => 'renewal', 'previous_period_ends_at' => $subscription->periodEndsAt()?->toISOString()],
            );
        } catch (QueryException $exception) {
            // Lost a race with a concurrent scheduler run; reuse its payment.
            $duplicate = SubscriptionPayment::query()
                ->where('company_subscription_id', $subscription->id)
                ->where('billing_period_key', $periodKey)
                ->latest('id')
                ->first();

            if (! $duplicate) {
                throw $exception;
            }

            return $duplicate;
        }
    }

    /**
     * Ask Paymob for a hosted checkout session and store the resulting URL.
     *
     * Idempotent by design: a paid invoice is never re-opened and a session
     * that is still live is handed back as-is, so a customer who clicks "pay"
     * twice lands on the same Paymob order instead of two payable ones.
     */
    public function openCheckout(SubscriptionPayment $payment): SubscriptionPayment
    {
        if ($payment->isSettled() || $payment->hasOpenCheckout()) {
            return $payment;
        }

        try {
            // Paymob rejects a special_reference it has already seen, so each
            // attempt gets its own and callbacks are matched through it.
            $attempt = (int) $payment->checkout_attempts + 1;
            $payment->forceFill([
                'checkout_attempts' => $attempt,
                'provider_reference' => $attempt === 1 ? $payment->reference : $payment->reference.'.'.$attempt,
            ])->save();

            $session = $this->gateway->createCheckout($payment, [
                'notification_url' => route('payments.paymob.callback'),
                // Paymob cannot post a server-to-server webhook to localhost.
                // Sending the customer's browser through the same signed
                // callback gives local development and delayed webhooks a
                // reliable way to settle the payment before React resumes.
                'redirection_url' => route('payments.paymob.redirect'),
            ]);

            $payment->forceFill([
                'provider_order_id' => $session['provider_order_id'] ?: $payment->provider_order_id,
                'checkout_url' => $session['checkout_url'] ?? null,
                'checkout_expires_at' => now()->addMinutes((int) config('subscriptions.checkout_session_ttl_minutes', 60)),
                'failure_reason' => null,
                'metadata' => array_replace($payment->metadata ?? [], [
                    // The client secret is only useful inside the checkout URL.
                    'gateway' => Arr::except($session, ['client_secret']),
                ]),
            ])->save();
        } catch (PaymobException $exception) {
            // Keep the payment pending so the customer can retry checkout
            // instead of losing the registration or renewal entirely.
            Log::error('[paymob] checkout session could not be created', [
                'reference' => $payment->reference,
                'message' => $exception->getMessage(),
            ] + $exception->context());

            $payment->forceFill([
                'failure_reason' => Str::limit($exception->getMessage(), 250, ''),
                'metadata' => array_replace($payment->metadata ?? [], ['checkout_error' => $exception->getMessage()]),
            ])->save();
        }

        return $payment->refresh();
    }

    public function createTrialActivation(User $admin, SubscriptionPlan $plan, string $billingCycle, string $plainToken): array
    {
        $pricing = $this->pricing->forCycle($plan, $billingCycle);
        $trialDays = (int) $plan->trial_days;

        return DB::transaction(function () use ($admin, $plan, $billingCycle, $plainToken, $pricing, $trialDays) {
            $company = $admin->company;
            $company->forceFill([
                'is_active' => true,
                'plan' => $plan->slug,
            ])->save();

            $subscription = $this->subscriptions->startTrial(
                $company,
                new SubscribeData($plan->slug, $billingCycle),
                $trialDays,
                [
                    'source' => 'trial',
                    'pricing' => $pricing,
                ],
            );

            $payment = SubscriptionPayment::create([
                'reference' => (string) Str::uuid(),
                'company_id' => $admin->company_id,
                'organization_id' => $company->organization_id,
                'initiated_from_company_id' => $admin->company_id,
                'company_subscription_id' => $subscription->id,
                'user_id' => $admin->id,
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'purpose' => PaymentPurpose::Registration->value,
                'period_starts_at' => $subscription->current_period_started_at,
                'period_ends_at' => $subscription->current_period_ends_at,
                'amount' => 0,
                'currency' => config('services.paymob.currency') ?: $plan->currency,
                'provider' => 'trial',
                'status' => SubscriptionPayment::STATUS_SUCCEEDED,
                'registration_token_hash' => hash('sha256', $plainToken),
                'paid_at' => now(),
                'metadata' => [
                    'source' => 'registration_trial',
                    'pricing' => $pricing,
                    'trial_days' => $trialDays,
                    'trial_ends_at' => $subscription->trial_ends_at?->toISOString(),
                    'subscription_id' => $subscription->id,
                ],
            ]);

            $this->financeSetup->provision($company);
            $this->inventorySetup->provision($company);

            return [
                'payment' => $payment->load('company', 'user.roles.permissions', 'plan.features'),
                'subscription' => $subscription->load('plan.features'),
            ];
        });
    }

    /* ---------------------------------------------------------------------
     | Lookups
     |--------------------------------------------------------------------- */

    public function findForRegistration(string $reference, string $token): SubscriptionPayment
    {
        $payment = SubscriptionPayment::with('company', 'user.roles.permissions', 'plan.features')
            ->where('reference', $reference)
            ->firstOrFail();

        if (! hash_equals((string) $payment->registration_token_hash, hash('sha256', $token))) {
            throw ValidationException::withMessages(['payment' => ['The checkout session is invalid.']]);
        }

        return $payment;
    }

    public function findForCompany(string $reference, Company $company): SubscriptionPayment
    {
        return SubscriptionPayment::with('company', 'plan.features')
            ->where('reference', $reference)
            ->when(
                $company->organization_id !== null,
                fn ($query) => $query->where('organization_id', $company->organization_id),
                fn ($query) => $query->where('company_id', $company->id),
            )
            ->firstOrFail();
    }

    public function history(Company $company, int $limit = 50)
    {
        return SubscriptionPayment::with('plan:id,slug,name')
            ->when(
                $company->organization_id !== null,
                fn ($query) => $query->where('organization_id', $company->organization_id),
                fn ($query) => $query->where('company_id', $company->id),
            )
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /* ---------------------------------------------------------------------
     | Resolving payments
     |--------------------------------------------------------------------- */

    /** Local-only shortcut used by the mock checkout screen. */
    public function resolveMock(string $reference, string $token, string $status): array
    {
        abort_unless(
            config('services.paymob.mode') === 'mock',
            404,
            'The mock checkout is disabled while Paymob is live.'
        );

        $payment = $this->findForRegistration($reference, $token);

        return match ($status) {
            'succeeded' => $this->markSucceeded($payment, ['mock_status' => $status]),
            'failed' => $this->markFailed($payment, ['mock_status' => $status], 'Mock checkout failure'),
            default => ['payment' => $payment->fresh(['company', 'plan.features']), 'auth' => null],
        };
    }

    /**
     * Entry point for both the server-to-server webhook and the browser
     * redirect Paymob sends the customer back through.
     */
    public function handleCallback(array $payload, ?string $signature): array
    {
        abort_unless($this->gateway->verifyCallback($payload, $signature), 403, 'Invalid Paymob signature.');

        $normalized = $this->gateway->normalizeCallback($payload);
        $payment = $this->locatePayment($normalized);

        if (! $payment) {
            Log::warning('[paymob] callback for an unknown payment', [
                'reference' => $normalized['reference'] ?? null,
                'order' => $normalized['provider_order_id'] ?? null,
                'type' => $normalized['type'] ?? null,
            ]);

            return ['payment' => null, 'auth' => null, 'handled' => false];
        }

        if (($normalized['type'] ?? 'transaction') === 'token') {
            return $this->storeCardToken($payment, $normalized);
        }

        // Serialize callback bookkeeping with settlement. In particular, a
        // late failure callback must never overwrite the transaction id that
        // captured the money in a concurrent success callback.
        $payment = DB::transaction(function () use ($payment, $normalized, $payload) {
            $locked = SubscriptionPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $settled = $locked->isSettled();

            $locked->forceFill([
                'provider_order_id' => $normalized['provider_order_id'] ?: $locked->provider_order_id,
                'provider_transaction_id' => $settled
                    ? $locked->provider_transaction_id
                    : ($normalized['provider_transaction_id'] ?: $locked->provider_transaction_id),
                'callback_payload' => $payload,
            ])->save();

            return $locked->refresh();
        });

        return match ($normalized['status']) {
            'succeeded' => $this->markSucceeded($payment, [
                'callback' => true,
                'provider_transaction_id' => $normalized['provider_transaction_id'],
            ]) + ['handled' => true],
            'refunded', 'voided' => $this->markRefunded($payment, $normalized['status']) + ['handled' => true],
            'pending' => ['payment' => $payment->fresh(['company', 'plan.features']), 'auth' => null, 'handled' => true],
            default => $this->markFailed($payment, ['callback' => true], $normalized['message']) + ['handled' => true],
        };
    }

    public function markSucceeded(SubscriptionPayment $payment, array $metadata = []): array
    {
        $capture = DB::transaction(function () use ($payment, $metadata) {
            $locked = SubscriptionPayment::query()
                ->with('company', 'user.roles.permissions', 'plan.features')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            // A committed activation-pending row means Paymob was already
            // acknowledged, but the activation transaction did not finish
            // (for example, the worker died). Re-entering is safe because the
            // entitlement mutation and final status update commit together.
            if ($locked->status === SubscriptionPayment::STATUS_ACTIVATION_PENDING) {
                $this->recordDuplicateCharge($locked, $metadata);
                $locked->forceFill([
                    'metadata' => array_replace($locked->metadata ?? [], $metadata),
                ])->save();

                return ['payment' => $locked->refresh(), 'activate' => true];
            }

            // Already paid: a repeated callback is a no-op, but a *different*
            // transaction means the customer was charged twice and someone
            // has to refund it, so record it loudly instead of silently.
            if ($locked->isSettled()) {
                $this->recordDuplicateCharge($locked, $metadata);

                return ['payment' => $locked->refresh(), 'activate' => false];
            }

            // A superseded invoice (an older checkout link that was paid after
            // a newer one settled) must not start a second subscription.
            if ($locked->wasSuperseded()) {
                $locked->forceFill([
                    'status' => SubscriptionPayment::STATUS_SUCCEEDED,
                    'paid_at' => now(),
                    'metadata' => array_replace($locked->metadata ?? [], $metadata + ['duplicate_payment' => true]),
                ])->save();

                Log::warning('[paymob] a superseded checkout was paid and needs a refund', [
                    'reference' => $locked->reference,
                    'company_id' => $locked->company_id,
                    'transaction' => $locked->provider_transaction_id,
                ]);

                return ['payment' => $locked, 'activate' => false];
            }

            $capturedAt = now();
            $locked->forceFill([
                'status' => SubscriptionPayment::STATUS_ACTIVATION_PENDING,
                'paid_at' => $capturedAt,
                'failed_at' => null,
                'failure_reason' => null,
                'next_retry_at' => null,
                'provider_transaction_id' => $metadata['provider_transaction_id']
                    ?? $locked->provider_transaction_id,
                'metadata' => array_replace($locked->metadata ?? [], $metadata, [
                    'provider_payment_captured' => true,
                    'provider_payment_captured_at' => $capturedAt->toISOString(),
                ]),
            ])->save();

            return ['payment' => $locked->refresh(), 'activate' => true];
        });

        if (! $capture['activate']) {
            return ['payment' => $capture['payment'], 'auth' => null];
        }

        try {
            $result = DB::transaction(function () use ($capture) {
                /** @var SubscriptionPayment $captured */
                $captured = $capture['payment'];
                $this->lockPaymentBillingOwner($captured);

                $locked = SubscriptionPayment::query()
                    ->with('company', 'user.roles.permissions', 'plan.features', 'subscription')
                    ->whereKey($captured->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Another callback may have completed activation while this
                // request waited for the organization lock.
                if ($locked->status !== SubscriptionPayment::STATUS_ACTIVATION_PENDING) {
                    return ['payment' => $locked, 'auth' => null];
                }

                $subscription = $locked->isRenewal()
                    ? $this->applyRenewal($locked)
                    : $this->activateFromPayment($locked);

                $locked->forceFill([
                    'status' => SubscriptionPayment::STATUS_SUCCEEDED,
                    'company_subscription_id' => $subscription?->id,
                    'failure_reason' => null,
                    'metadata' => array_replace($locked->metadata ?? [], [
                        'activation_completed_at' => now()->toISOString(),
                    ]),
                ])->save();
                $this->supersedeOpenInvoices($locked);

                return [
                    'payment' => $locked->fresh(['company', 'plan.features']),
                    'subscription' => $subscription?->fresh(['plan.features']),
                    'auth' => null,
                ];
            });
        } catch (Throwable $exception) {
            Log::error('[subscriptions] payment captured but entitlement activation failed', [
                'payment_id' => $payment->getKey(),
                'reference' => $payment->reference,
                'organization_id' => $payment->organization_id,
                'company_id' => $payment->company_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return DB::transaction(function () use ($payment, $exception) {
                $this->lockPaymentBillingOwner($payment);
                $locked = SubscriptionPayment::query()
                    ->with('company', 'plan.features')
                    ->whereKey($payment->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Do not overwrite a successful activation completed by a
                // concurrent callback after the failed attempt rolled back.
                if ($locked->status === SubscriptionPayment::STATUS_ACTIVATION_PENDING) {
                    $activationError = $exception instanceof OrganizationQuotaExceededException
                        ? [
                            'code' => $exception->errorCode,
                            'message' => $exception->getMessage(),
                            'details' => $exception->details,
                        ]
                        : [
                            'code' => 'SUBSCRIPTION_ACTIVATION_FAILED',
                            'message' => 'Subscription activation failed after payment capture.',
                        ];

                    $locked->forceFill([
                        'status' => SubscriptionPayment::STATUS_REQUIRES_REVIEW,
                        'failure_reason' => 'Payment captured; subscription activation requires manual review.',
                        'checkout_url' => null,
                        'checkout_expires_at' => null,
                        'next_retry_at' => null,
                        'metadata' => array_replace($locked->metadata ?? [], [
                            'activation_error' => $activationError + ['occurred_at' => now()->toISOString()],
                        ]),
                    ])->save();
                    $this->supersedeOpenInvoices($locked);
                }

                return [
                    'payment' => $locked->fresh(['company', 'plan.features']),
                    'subscription' => null,
                    'auth' => null,
                ];
            });
        }

        if (($result['subscription'] ?? null) instanceof CompanySubscription) {
            try {
                $this->notifier->paymentSucceeded($result['subscription'], $result['payment']);
            } catch (Throwable $exception) {
                // Notification delivery is not part of entitlement integrity;
                // payment and activation have already committed successfully.
                Log::error('[subscriptions] payment succeeded notification failed', [
                    'payment_id' => $result['payment']->getKey(),
                    'subscription_id' => $result['subscription']->getKey(),
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return $result;
    }

    public function markFailed(SubscriptionPayment $payment, array $metadata = [], ?string $reason = null): array
    {
        $result = DB::transaction(function () use ($payment, $metadata, $reason) {
            $locked = SubscriptionPayment::query()
                ->with('company', 'plan.features', 'subscription')
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isSettled()) {
                return ['payment' => $locked, 'subscription' => null, 'notify' => false];
            }

            $locked->forceFill([
                'status' => SubscriptionPayment::STATUS_FAILED,
                'failed_at' => now(),
                'failure_reason' => $reason ? Str::limit($reason, 250, '') : $locked->failure_reason,
                'metadata' => array_replace($locked->metadata ?? [], $metadata),
            ])->save();

            return [
                'payment' => $locked->fresh(['company', 'plan.features']),
                'subscription' => $locked->subscription,
                'notify' => $locked->isRenewal() && $locked->subscription !== null,
            ];
        });

        if ($result['notify']) {
            $this->notifier->paymentFailed($result['subscription'], $result['payment'], $reason);
        }

        return ['payment' => $result['payment'], 'auth' => null];
    }

    public function markRefunded(SubscriptionPayment $payment, string $status = 'refunded'): array
    {
        return DB::transaction(function () use ($payment, $status) {
            // Read only the immutable owner key before taking locks, then use
            // the same owner -> payment order as successful activation.
            $identity = SubscriptionPayment::query()
                ->select(['id', 'company_id', 'organization_id'])
                ->findOrFail($payment->getKey());
            $this->lockPaymentBillingOwner($identity);

            $locked = SubscriptionPayment::query()
                ->whereKey($payment->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === SubscriptionPayment::STATUS_REFUNDED) {
                return ['payment' => $locked->fresh(['company', 'plan.features']), 'auth' => null];
            }

            // Only a completed activation owns a service period to revoke.
            // A refunded activation-pending/manual-review or superseded charge
            // must leave the customer's previous subscription untouched.
            $shouldExpireSubscription = $locked->status === SubscriptionPayment::STATUS_SUCCEEDED
                && ! $locked->wasSuperseded()
                && $locked->company_subscription_id !== null;

            $locked->forceFill([
                'status' => SubscriptionPayment::STATUS_REFUNDED,
                'refunded_at' => now(),
                'metadata' => array_replace($locked->metadata ?? [], ['provider_status' => $status]),
            ])->save();

            if ($shouldExpireSubscription) {
                $subscription = CompanySubscription::query()
                    ->whereKey($locked->company_subscription_id)
                    ->lockForUpdate()
                    ->first();

                if ($subscription && in_array($subscription->status, SubscriptionStatus::servingValues(), true)) {
                    $this->lifecycle->expire($subscription, "payment_{$status}");
                }
            }

            return ['payment' => $locked->fresh(['company', 'plan.features']), 'auth' => null];
        });
    }

    /* ---------------------------------------------------------------------
     | Internals
     |--------------------------------------------------------------------- */

    /** Keep activation lock order consistent with plan changes and renewals. */
    private function lockPaymentBillingOwner(SubscriptionPayment $payment): void
    {
        if ($payment->organization_id !== null) {
            Organization::query()
                ->whereKey($payment->organization_id)
                ->lockForUpdate()
                ->firstOrFail();

            return;
        }

        Company::query()
            ->whereKey($payment->company_id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function applyRenewal(SubscriptionPayment $payment): ?CompanySubscription
    {
        $subscription = $payment->subscription;

        if (! $subscription) {
            return $this->activateFromPayment($payment);
        }

        return $this->lifecycle->applyRenewal($subscription, $payment);
    }

    /** Registration and in-app upgrades both (re)start a subscription. */
    private function activateFromPayment(SubscriptionPayment $payment): ?CompanySubscription
    {
        $company = $payment->company;

        if (! $company) {
            return null;
        }

        if ($payment->isRegistration()) {
            $company->forceFill(['is_active' => true])->save();
        }

        $subscription = $this->subscriptions->start(
            $company,
            new SubscribeData($payment->plan->slug, $payment->billing_cycle),
            [
                'source' => $payment->provider,
                'purpose' => $payment->purpose,
                'payment_reference' => $payment->reference,
                'provider_order_id' => $payment->provider_order_id,
            ],
        );

        $this->financeSetup->provision($company);
        $this->inventorySetup->provision($company);

        return $subscription;
    }

    private function storeCardToken(SubscriptionPayment $payment, array $normalized): array
    {
        $subscription = $payment->subscription
            ?? ($payment->company ? $this->subscriptions->currentForCompany($payment->company) : null);

        if ($subscription && $normalized['card_token']) {
            $this->lifecycle->storePaymentMethod(
                $subscription,
                $normalized['card_token'],
                $normalized['card_brand'],
                $normalized['card_last4'],
            );
        }

        return [
            'payment' => $payment->fresh(['company', 'plan.features']),
            'subscription' => $subscription?->fresh(),
            'auth' => null,
            'handled' => true,
        ];
    }

    /**
     * Close every other open checkout for the company once one is paid, so a
     * stale link cannot be used to buy the same thing twice.
     */
    private function supersedeOpenInvoices(SubscriptionPayment $paid): void
    {
        SubscriptionPayment::query()
            ->when(
                $paid->organization_id !== null,
                fn ($query) => $query->where('organization_id', $paid->organization_id),
                fn ($query) => $query->where('company_id', $paid->company_id),
            )
            ->whereKeyNot($paid->id)
            ->where('status', SubscriptionPayment::STATUS_PENDING)
            ->whereIn('purpose', [PaymentPurpose::Registration->value, PaymentPurpose::Upgrade->value])
            ->get()
            ->each(function (SubscriptionPayment $stale) use ($paid) {
                $stale->forceFill([
                    'status' => SubscriptionPayment::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_reason' => 'Superseded by payment '.$paid->reference,
                    'checkout_url' => null,
                    'checkout_expires_at' => null,
                    'metadata' => array_replace($stale->metadata ?? [], ['superseded_by' => $paid->reference]),
                ])->save();
            });
    }

    private function recordDuplicateCharge(SubscriptionPayment $payment, array $metadata): void
    {
        $transaction = isset($metadata['provider_transaction_id']) ? (string) $metadata['provider_transaction_id'] : null;

        if (! $transaction || $transaction === (string) $payment->provider_transaction_id) {
            return;
        }

        $duplicates = array_values(array_unique(array_merge(
            (array) data_get($payment->metadata, 'duplicate_transactions', []),
            [$transaction],
        )));

        $payment->forceFill([
            'metadata' => array_replace($payment->metadata ?? [], ['duplicate_transactions' => $duplicates]),
        ])->save();

        Log::warning('[paymob] a second successful transaction arrived for a paid invoice', [
            'reference' => $payment->reference,
            'company_id' => $payment->company_id,
            'settled_transaction' => $payment->provider_transaction_id,
            'duplicate_transaction' => $transaction,
        ]);
    }

    private function locatePayment(array $normalized): ?SubscriptionPayment
    {
        $query = SubscriptionPayment::with('company', 'user.roles.permissions', 'plan.features', 'subscription');

        if (! empty($normalized['reference'])) {
            // The callback carries the per-attempt reference; fall back to the
            // payment reference for sessions opened before retries existed.
            $payment = (clone $query)
                ->where('provider_reference', $normalized['reference'])
                ->orWhere('reference', $normalized['reference'])
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if (! empty($normalized['provider_order_id'])) {
            return (clone $query)->where('provider_order_id', (string) $normalized['provider_order_id'])->latest('id')->first();
        }

        return null;
    }

    private function newPayment(
        ?Company $company,
        ?User $user,
        SubscriptionPlan $plan,
        string $billingCycle,
        PaymentPurpose $purpose,
        array $attributes = [],
        array $metadata = [],
    ): SubscriptionPayment {
        abort_unless($company !== null, 422, 'A company is required to start a checkout session.');
        abort_unless($user !== null, 422, 'A billing contact is required to start a checkout session.');

        $pricing = $this->pricing->forCycle($plan, $billingCycle);

        return SubscriptionPayment::create(array_replace([
            'reference' => (string) Str::uuid(),
            'company_id' => $company->id,
            'organization_id' => $company->organization_id,
            'initiated_from_company_id' => $company->id,
            'user_id' => $user->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'purpose' => $purpose->value,
            'amount' => $pricing['final_amount'],
            'currency' => config('services.paymob.currency') ?: $plan->currency,
            'provider' => 'paymob',
            'status' => SubscriptionPayment::STATUS_PENDING,
            'metadata' => $metadata + ['pricing' => $pricing],
        ], $attributes));
    }

    private function billingContact(CompanySubscription $subscription): ?User
    {
        $companyId = $subscription->company_id;

        if ($subscription->organization_id !== null) {
            $billingContact = User::query()
                ->where('users.is_active', true)
                ->join('organization_memberships', 'organization_memberships.user_id', '=', 'users.id')
                ->where('organization_memberships.organization_id', $subscription->organization_id)
                ->where('organization_memberships.status', 'active')
                ->whereIn('organization_memberships.role', ['owner', 'billing_admin'])
                ->orderByRaw("CASE organization_memberships.role WHEN 'owner' THEN 0 ELSE 1 END")
                ->orderBy('users.id')
                ->select('users.*')
                ->first();

            if ($billingContact) {
                return $billingContact;
            }
        }

        return User::query()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->where('name', UserRole::CompanyAdmin->value))
            ->oldest('id')
            ->first()
            ?? User::query()->where('company_id', $companyId)->oldest('id')->first();
    }

    public function nextRetryAt(int $attempts): Carbon
    {
        $interval = max((int) config('subscriptions.retry.interval_hours', 24), 1);

        return now()->addHours($interval * max($attempts, 1));
    }
}
