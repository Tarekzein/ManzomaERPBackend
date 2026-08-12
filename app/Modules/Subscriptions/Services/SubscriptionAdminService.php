<?php

namespace App\Modules\Subscriptions\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Platform\Services\AuditService;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use App\Modules\Subscriptions\Policies\SubscriptionPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SubscriptionAdminService
{
    public function __construct(
        private readonly SubscriptionPolicy $policy,
        private readonly SubscriptionLifecycleService $lifecycle,
        private readonly OrganizationQuotaService $quotas,
        private readonly AuditService $audit,
    ) {}

    /**
     * Platform-wide billing data with callback payloads and payment tokens
     * intentionally excluded from the response.
     */
    public function overview(User $actor): array
    {
        $this->policy->ensureCanManageCompanySubscriptions($actor);

        $organizationModels = Organization::query()
            ->withCount('companies')
            ->orderBy('name')
            ->get();
        $organizationSubscriptions = CompanySubscription::query()
            ->with('plan')
            ->whereIn('organization_id', $organizationModels->modelKeys())
            ->latest('id')
            ->get()
            ->groupBy('organization_id')
            ->map(fn ($subscriptions) => $subscriptions->first(
                fn (CompanySubscription $subscription) => in_array(
                    $subscription->status,
                    SubscriptionStatus::servingValues(),
                    true,
                )
            ) ?? $subscriptions->first());

        $companies = Company::query()
            ->with(['latestSubscription.plan', 'organization'])
            ->withCount('subscriptionPayments')
            ->orderBy('name')
            ->get()
            ->map(function (Company $company) use ($organizationSubscriptions) {
                $subscription = $company->organization
                    ? $organizationSubscriptions->get($company->organization_id)
                    : $company->latestSubscription;

                return [
                    'id' => $company->id,
                    'name' => $company->name,
                    'is_active' => $company->is_active,
                    'organization_id' => $company->organization_id,
                    'payments_count' => $company->subscription_payments_count,
                    'subscription' => $subscription ? $this->subscriptionData($subscription) : null,
                ];
            })
            ->values();

        $organizations = $organizationModels
            ->map(function (Organization $organization) use ($organizationSubscriptions) {
                $subscription = $organizationSubscriptions->get($organization->id);

                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'status' => $organization->status,
                    'billing_suspended_at' => $organization->billing_suspended_at,
                    'companies_count' => $organization->companies_count,
                    'members_count' => DB::table('organization_memberships')
                        ->where('organization_id', $organization->id)
                        ->where('status', 'active')
                        ->count(),
                    'usage' => $this->quotas->usage($organization),
                    'subscription' => $subscription ? $this->subscriptionData($subscription) : null,
                ];
            })
            ->values();

        $payments = SubscriptionPayment::query()
            ->with([
                'company:id,name',
                'organization:id,name',
                'initiatedFromCompany:id,name',
                'plan:id,slug,name',
                'user:id,name,email',
            ])
            ->latest('id')
            ->get()
            ->map(fn (SubscriptionPayment $payment) => [
                'id' => $payment->id,
                'reference' => $payment->reference,
                'company_id' => $payment->company_id,
                'organization_id' => $payment->organization_id,
                'initiated_from_company_id' => $payment->initiated_from_company_id,
                'company_subscription_id' => $payment->company_subscription_id,
                'user_id' => $payment->user_id,
                'subscription_plan_id' => $payment->subscription_plan_id,
                'billing_cycle' => $payment->billing_cycle,
                'purpose' => $payment->purpose,
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'provider' => $payment->provider,
                'status' => $payment->status,
                'attempts' => $payment->attempts,
                'failure_reason' => $payment->failure_reason,
                'provider_order_id' => $payment->provider_order_id,
                'provider_transaction_id' => $payment->provider_transaction_id,
                'period_starts_at' => $payment->period_starts_at,
                'period_ends_at' => $payment->period_ends_at,
                'paid_at' => $payment->paid_at,
                'failed_at' => $payment->failed_at,
                'refunded_at' => $payment->refunded_at,
                'created_at' => $payment->created_at,
                'company' => $payment->company,
                'organization' => $payment->organization,
                'initiated_from_company' => $payment->initiatedFromCompany,
                'plan' => $payment->plan,
                'user' => $payment->user,
            ])
            ->values();

        return compact('organizations', 'companies', 'payments');
    }

    /**
     * Grant service through an explicit date without creating a payment.
     * The period is marked to end on that date so no automatic charge or
     * manual payment request is generated for the complimentary extension.
     */
    public function renewWithoutPayment(
        User $actor,
        Company $company,
        Carbon $through,
        ?string $reason = null,
    ): CompanySubscription {
        $this->policy->ensureCanManageCompanySubscriptions($actor);

        $through = $through->copy()->endOfDay();

        return DB::transaction(function () use ($actor, $company, $through, $reason) {
            $organization = null;
            if ($company->organization_id !== null) {
                // Every organization-level subscription mutation takes this
                // lock first. Selecting the subscription afterwards prevents
                // a stale expired row from being reactivated over a newer one.
                $organization = Organization::query()
                    ->whereKey($company->organization_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                Company::query()->whereKey($company->getKey())->lockForUpdate()->firstOrFail();
            }

            $scope = CompanySubscription::query()
                ->with('plan')
                ->when(
                    $organization !== null,
                    fn ($query) => $query->where('organization_id', $organization->getKey()),
                    fn ($query) => $query->where('company_id', $company->getKey()),
                );

            $subscription = (clone $scope)
                ->whereIn('status', SubscriptionStatus::servingValues())
                ->latest('id')
                ->lockForUpdate()
                ->first()
                ?? (clone $scope)->latest('id')->lockForUpdate()->first();

            abort_unless($subscription, 404, 'This company does not have a subscription to renew.');

            $currentEnd = $subscription->periodEndsAt();
            abort_if(
                $currentEnd?->gte($through),
                422,
                'The complimentary renewal date must be after the current subscription end date.'
            );

            // Defensive repair for legacy or previously raced data. The
            // organization/company lock prevents another supported writer
            // from introducing a second serving row while this is applied.
            (clone $scope)
                ->whereKeyNot($subscription->getKey())
                ->whereIn('status', SubscriptionStatus::servingValues())
                ->lockForUpdate()
                ->get()
                ->each(function (CompanySubscription $stale) use ($subscription) {
                    $stale->forceFill([
                        'status' => SubscriptionStatus::Cancelled->value,
                        'auto_renew' => false,
                        'cancel_at_period_end' => false,
                        'cancelled_at' => now(),
                        'ends_at' => now(),
                        'cancellation_reason' => 'superseded_by_admin_complimentary_renewal',
                        'metadata' => array_replace($stale->metadata ?? [], [
                            'superseded_by_subscription_id' => $subscription->getKey(),
                        ]),
                    ])->save();
                });

            $old = [
                'status' => $subscription->status,
                'current_period_ends_at' => $currentEnd?->toISOString(),
                'auto_renew' => $subscription->auto_renew,
                'cancel_at_period_end' => $subscription->cancel_at_period_end,
            ];
            $metadata = $subscription->metadata ?? [];
            $history = (array) data_get($metadata, 'admin_renewals', []);
            $history[] = [
                'through' => $through->toISOString(),
                'reason' => $reason,
                'admin_user_id' => $actor->id,
                'granted_at' => now()->toISOString(),
            ];

            $subscription->forceFill([
                'status' => SubscriptionStatus::Active->value,
                'current_period_started_at' => $currentEnd?->isFuture()
                    ? $subscription->current_period_started_at
                    : now(),
                'current_period_ends_at' => $through,
                'trial_ends_at' => null,
                'grace_ends_at' => null,
                'ends_at' => $through,
                'cancelled_at' => null,
                'cancellation_reason' => null,
                'auto_renew' => false,
                'cancel_at_period_end' => true,
                'renewal_failures' => 0,
                'last_renewal_attempt_at' => null,
                'last_renewed_at' => now(),
                'metadata' => array_replace($metadata, [
                    'admin_renewals' => array_slice($history, -50),
                    'last_admin_renewal_reason' => $reason,
                ]),
            ])->save();

            $this->lifecycle->restoreAccess($subscription->loadMissing('company', 'plan'));
            $subscription = $subscription->refresh()->load('company', 'plan.features');

            $this->audit->record(
                'subscription.admin_renewed_without_payment',
                $subscription,
                $old,
                [
                    'status' => $subscription->status,
                    'current_period_ends_at' => $through->toISOString(),
                    'auto_renew' => false,
                    'cancel_at_period_end' => true,
                    'reason' => $reason,
                    'company_id' => $company->id,
                    'organization_id' => $organization?->id,
                ],
            );

            return $subscription;
        });
    }

    private function subscriptionData(CompanySubscription $subscription): array
    {
        return [
            'id' => $subscription->id,
            'company_id' => $subscription->company_id,
            'organization_id' => $subscription->organization_id,
            'billing_cycle' => $subscription->billing_cycle,
            'status' => $subscription->status,
            'auto_renew' => $subscription->auto_renew,
            'cancel_at_period_end' => $subscription->cancel_at_period_end,
            'starts_at' => $subscription->starts_at,
            'current_period_started_at' => $subscription->current_period_started_at,
            'current_period_ends_at' => $subscription->current_period_ends_at,
            'trial_ends_at' => $subscription->trial_ends_at,
            'grace_ends_at' => $subscription->grace_ends_at,
            'ends_at' => $subscription->ends_at,
            'last_renewed_at' => $subscription->last_renewed_at,
            'plan' => $subscription->plan,
        ];
    }
}
