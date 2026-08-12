<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Enums\PaymentPurpose;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_REFUNDED = 'refunded';

    /** Paymob captured the money and entitlement activation is in progress. */
    public const STATUS_ACTIVATION_PENDING = 'activation_pending';

    /** Money was captured, but an operator must resolve activation or refund it. */
    public const STATUS_REQUIRES_REVIEW = 'requires_review';

    protected $fillable = [
        'reference',
        'company_id',
        'organization_id',
        'initiated_from_company_id',
        'company_subscription_id',
        'user_id',
        'subscription_plan_id',
        'billing_cycle',
        'purpose',
        'billing_period_key',
        'period_starts_at',
        'period_ends_at',
        'amount',
        'currency',
        'provider',
        'status',
        'attempts',
        'next_retry_at',
        'failure_reason',
        'provider_order_id',
        'provider_reference',
        'checkout_attempts',
        'provider_transaction_id',
        'checkout_url',
        'checkout_expires_at',
        'registration_token_hash',
        'callback_payload',
        'metadata',
        'paid_at',
        'failed_at',
        'refunded_at',
    ];

    protected $hidden = ['registration_token_hash'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'attempts' => 'integer',
            'checkout_attempts' => 'integer',
            'callback_payload' => 'array',
            'metadata' => 'array',
            'period_starts_at' => 'datetime',
            'period_ends_at' => 'datetime',
            'next_retry_at' => 'datetime',
            'checkout_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'failed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function initiatedFromCompany(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'initiated_from_company_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(CompanySubscription::class, 'company_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function isSuccessful(): bool
    {
        return $this->status === self::STATUS_SUCCEEDED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isRenewal(): bool
    {
        return $this->purpose === PaymentPurpose::Renewal->value;
    }

    public function isRegistration(): bool
    {
        return $this->purpose === PaymentPurpose::Registration->value;
    }

    public function checkoutHasExpired(): bool
    {
        return $this->checkout_expires_at !== null && $this->checkout_expires_at->isPast();
    }

    /** A live hosted session the customer can still pay on. */
    public function hasOpenCheckout(): bool
    {
        return filled($this->checkout_url) && ! $this->checkoutHasExpired();
    }

    /** The merchant reference sent to Paymob for the current attempt. */
    public function providerReference(): string
    {
        return $this->provider_reference ?: $this->reference;
    }

    public function isSettled(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCEEDED,
            self::STATUS_REFUNDED,
            self::STATUS_ACTIVATION_PENDING,
            self::STATUS_REQUIRES_REVIEW,
        ], true);
    }

    /** Closed because a newer checkout for the same company was paid first. */
    public function wasSuperseded(): bool
    {
        return filled(data_get($this->metadata, 'superseded_by'));
    }
}
