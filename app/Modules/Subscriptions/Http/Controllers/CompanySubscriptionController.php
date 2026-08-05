<?php

namespace App\Modules\Subscriptions\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Authentication\Models\User;
use App\Modules\Subscriptions\Contracts\PlanRepository;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Http\Requests\SubscribeRequest;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Services\CompanySubscriptionService;
use App\Modules\Subscriptions\Services\PlanPricingService;
use App\Modules\Subscriptions\Services\SubscriptionPaymentService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySubscriptionController extends Controller
{
    public function __construct(
        private readonly CompanySubscriptionService $subscriptions,
        private readonly SubscriptionPaymentService $payments,
        private readonly PlanRepository $plans,
        private readonly PlanPricingService $pricing,
    ) {}

    public function current(Request $request): JsonResponse
    {
        $subscription = $this->subscriptions->current($this->user($request));

        return ApiResponse::success(
            $subscription ? $this->decorate($subscription) : null,
            'Current subscription loaded'
        );
    }

    /**
     * Free plans activate immediately; anything with a price returns a Paymob
     * checkout session that must be paid before the plan switches over.
     */
    public function subscribe(SubscribeRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = SubscribeData::from($request->validated());
        $plan = $this->plans->findActiveBySlug($data->planSlug);
        $pricing = $this->pricing->forCycle($plan, $data->billingCycle);

        if ($this->subscriptions->trialEligible($user->company, $plan)) {
            return ApiResponse::success(
                $this->decorate($this->subscriptions->startTrialFor($user, $plan, $data->billingCycle)),
                "Free trial started for {$plan->trial_days} days",
                status: 201
            );
        }

        if ((float) $pricing['final_amount'] <= 0) {
            return ApiResponse::success(
                $this->decorate($this->subscriptions->subscribe($user, $data)),
                'Company subscription activated',
                status: 201
            );
        }

        return ApiResponse::success(
            $this->checkoutPayload($this->payments->createUpgradeCheckout($user, $plan, $data->billingCycle)),
            'Complete the payment to activate this plan',
            status: 202
        );
    }

    /** Explicit checkout for a plan change or an early/manual renewal. */
    public function checkout(SubscribeRequest $request): JsonResponse
    {
        $user = $this->user($request);
        $data = SubscribeData::from($request->validated());
        $plan = $this->plans->findActiveBySlug($data->planSlug);

        if ($request->boolean('start_trial') && $this->subscriptions->trialEligible($user->company, $plan)) {
            return ApiResponse::success(
                $this->decorate($this->subscriptions->startTrialFor($user, $plan, $data->billingCycle)),
                "Free trial started for {$plan->trial_days} days",
                status: 201
            );
        }

        return ApiResponse::success(
            $this->checkoutPayload($this->payments->createUpgradeCheckout($user, $plan, $data->billingCycle)),
            'Checkout session created',
            status: 201
        );
    }

    /** Pay the current subscription's next period by hand. */
    public function renew(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->subscriptions->ensureCanManageBilling($user);
        $subscription = $this->subscriptions->current($user);
        abort_unless($subscription, 404, 'No active subscription was found.');

        $payment = $this->payments->openCheckout($this->payments->createRenewalPayment($subscription));

        abort_if($payment->checkout_url === null, 502, 'The payment gateway could not start a checkout session. Please try again.');

        return ApiResponse::success($this->checkoutPayload($payment), 'Renewal checkout session created', status: 201);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'immediately' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:250'],
        ]);

        $immediately = (bool) ($data['immediately'] ?? false);
        $subscription = $this->subscriptions->cancel($this->user($request), $immediately, $data['reason'] ?? null);

        return ApiResponse::success(
            $this->decorate($subscription),
            $immediately
                ? 'Company subscription cancelled'
                : 'Company subscription will not renew and stays active until the end of the period'
        );
    }

    public function resume(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->decorate($this->subscriptions->resume($this->user($request))),
            'Company subscription resumed'
        );
    }

    public function autoRenew(Request $request): JsonResponse
    {
        $data = $request->validate(['auto_renew' => ['required', 'boolean']]);

        return ApiResponse::success(
            $this->decorate($this->subscriptions->setAutoRenew($this->user($request), (bool) $data['auto_renew'])),
            $data['auto_renew'] ? 'Automatic renewal enabled' : 'Automatic renewal disabled'
        );
    }

    public function forgetPaymentMethod(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->decorate($this->subscriptions->forgetPaymentMethod($this->user($request))),
            'Saved card removed'
        );
    }

    public function payments(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $this->subscriptions->ensureCanManageBilling($user);

        return ApiResponse::success(
            $this->payments->history($user->company),
            'Billing history loaded'
        );
    }

    /** Poll a checkout session started from inside the app. */
    public function payment(Request $request, string $reference): JsonResponse
    {
        $user = $this->user($request);
        $this->subscriptions->ensureCanManageBilling($user);

        return ApiResponse::success(
            $this->payments->findForCompany($reference, $user->company),
            'Payment status loaded'
        );
    }

    private function decorate(CompanySubscription $subscription): CompanySubscription
    {
        $subscription->loadMissing('plan.features');
        $subscription->setAttribute('payment_method', $subscription->hasSavedCard() ? [
            'brand' => $subscription->payment_method_brand,
            'last4' => $subscription->payment_method_last4,
        ] : null);
        $subscription->setAttribute('renews_at', $subscription->auto_renew && ! $subscription->cancel_at_period_end
            ? $subscription->periodEndsAt()?->toISOString()
            : null);
        $subscription->setAttribute('access_ends_at', $subscription->accessEndsAt()?->toISOString());
        $subscription->setAttribute('trial_used', $subscription->company_id
            ? $this->subscriptions->hasUsedTrial($subscription->company)
            : false);

        return $subscription;
    }

    private function checkoutPayload($payment): array
    {
        return [
            'requires_payment' => true,
            'checkout' => [
                'reference' => $payment->reference,
                'checkout_url' => $payment->checkout_url,
                'expires_at' => $payment->checkout_expires_at?->toISOString(),
                'status' => $payment->status,
                'error' => $payment->failure_reason,
                'mode' => config('services.paymob.mode'),
            ],
            'payment' => $payment,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
