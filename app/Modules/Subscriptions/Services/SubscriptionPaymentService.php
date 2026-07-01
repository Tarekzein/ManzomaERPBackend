<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Subscriptions\Contracts\PaymobGateway;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionPaymentService
{
    public function __construct(
        private readonly PaymobGateway $gateway,
        private readonly CompanySubscriptionService $subscriptions,
        private readonly FinanceSetupService $financeSetup,
        private readonly InventorySetupService $inventorySetup,
        private readonly PlanPricingService $pricing,
    ) {}

    public function createRegistrationPayment(User $admin, SubscriptionPlan $plan, string $billingCycle, string $plainToken): SubscriptionPayment
    {
        $pricing = $this->pricing->forCycle($plan, $billingCycle);
        $amount = $pricing['final_amount'];
        $currency = config('services.paymob.currency') ?: $plan->currency;

        $payment = SubscriptionPayment::create([
            'reference' => (string) Str::uuid(),
            'company_id' => $admin->company_id,
            'user_id' => $admin->id,
            'subscription_plan_id' => $plan->id,
            'billing_cycle' => $billingCycle,
            'amount' => $amount,
            'currency' => $currency,
            'provider' => 'paymob',
            'status' => 'pending',
            'registration_token_hash' => hash('sha256', $plainToken),
            'metadata' => [
                'source' => 'registration',
                'pricing' => $pricing,
            ],
        ]);

        $order = $this->gateway->createOrder($payment);
        $payment->forceFill([
            'provider_order_id' => $order['provider_order_id'] ?? null,
            'checkout_url' => $order['checkout_url'] ?? null,
            'metadata' => array_replace($payment->metadata ?? [], ['gateway' => $order]),
        ])->save();

        return $payment->load('company', 'user.roles.permissions', 'plan.features');
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
                'user_id' => $admin->id,
                'subscription_plan_id' => $plan->id,
                'billing_cycle' => $billingCycle,
                'amount' => 0,
                'currency' => config('services.paymob.currency') ?: $plan->currency,
                'provider' => 'trial',
                'status' => 'succeeded',
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

    public function resolveMock(string $reference, string $token, string $status): array
    {
        $payment = $this->findForRegistration($reference, $token);

        return match ($status) {
            'succeeded' => $this->markSucceeded($payment, ['mock_status' => $status]),
            'failed' => $this->markFailed($payment, ['mock_status' => $status]),
            default => ['payment' => $payment->fresh(['company', 'plan.features']), 'auth' => null],
        };
    }

    public function handleCallback(array $payload, ?string $signature): array
    {
        abort_unless($this->gateway->verifyCallback($payload, $signature), 403, 'Invalid Paymob signature.');

        $normalized = $this->gateway->normalizeCallback($payload);
        $payment = SubscriptionPayment::with('company', 'user.roles.permissions', 'plan.features')
            ->where('reference', $normalized['reference'])
            ->firstOrFail();

        $payment->forceFill([
            'provider_order_id' => $normalized['provider_order_id'] ?: $payment->provider_order_id,
            'provider_transaction_id' => $normalized['provider_transaction_id'] ?: $payment->provider_transaction_id,
            'callback_payload' => $payload,
        ])->save();

        return $normalized['status'] === 'succeeded'
            ? $this->markSucceeded($payment, ['callback' => true])
            : $this->markFailed($payment, ['callback' => true]);
    }

    public function markSucceeded(SubscriptionPayment $payment, array $metadata = []): array
    {
        return DB::transaction(function () use ($payment, $metadata) {
            $locked = SubscriptionPayment::query()
                ->with('company', 'user.roles.permissions', 'plan.features')
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->isSuccessful()) {
                return ['payment' => $locked, 'auth' => null];
            }

            $locked->forceFill([
                'status' => 'succeeded',
                'paid_at' => now(),
                'failed_at' => null,
                'metadata' => array_replace($locked->metadata ?? [], $metadata),
            ])->save();

            $company = $locked->company;
            $company->forceFill([
                'is_active' => true,
                'plan' => $locked->plan->slug,
            ])->save();

            $this->subscriptions->start(
                $company,
                new SubscribeData($locked->plan->slug, $locked->billing_cycle),
                [
                    'source' => 'paymob',
                    'payment_reference' => $locked->reference,
                    'provider_order_id' => $locked->provider_order_id,
                ],
            );
            $this->financeSetup->provision($company);
            $this->inventorySetup->provision($company);

            return ['payment' => $locked->fresh(['company', 'plan.features']), 'auth' => null];
        });
    }

    public function markFailed(SubscriptionPayment $payment, array $metadata = []): array
    {
        if ($payment->isSuccessful()) {
            return ['payment' => $payment, 'auth' => null];
        }

        $payment->forceFill([
            'status' => 'failed',
            'failed_at' => now(),
            'metadata' => array_replace($payment->metadata ?? [], $metadata),
        ])->save();

        return ['payment' => $payment->fresh(['company', 'plan.features']), 'auth' => null];
    }
}
