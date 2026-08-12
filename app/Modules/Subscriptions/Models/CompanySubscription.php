<?php

namespace App\Modules\Subscriptions\Models;

use App\Modules\Companies\Models\Company;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Support\BillingPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class CompanySubscription extends Model
{
    use HasFactory;

    protected $fillable = [
        'company_id', 'organization_id', 'subscription_plan_id', 'entitlements_snapshot',
        'over_limit_since', 'pending_plan_id', 'pending_change_at', 'status', 'billing_cycle', 'auto_renew',
        'cancel_at_period_end', 'starts_at', 'current_period_started_at', 'current_period_ends_at',
        'ends_at', 'trial_ends_at', 'grace_ends_at', 'cancelled_at', 'cancellation_reason',
        'provider', 'provider_subscription_id', 'payment_method_token', 'payment_method_brand',
        'payment_method_last4', 'renewal_failures', 'last_renewal_attempt_at', 'last_renewed_at',
        'reminders_sent', 'metadata',
    ];

    protected $hidden = ['payment_method_token'];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'current_period_started_at' => 'datetime',
            'current_period_ends_at' => 'datetime',
            'ends_at' => 'datetime',
            'trial_ends_at' => 'datetime',
            'grace_ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_renewal_attempt_at' => 'datetime',
            'last_renewed_at' => 'datetime',
            'over_limit_since' => 'datetime',
            'pending_change_at' => 'datetime',
            'auto_renew' => 'boolean',
            'cancel_at_period_end' => 'boolean',
            'renewal_failures' => 'integer',
            'reminders_sent' => 'array',
            'metadata' => 'array',
            'entitlements_snapshot' => 'array',
            'payment_method_token' => 'encrypted',
        ];
    }

    protected $appends = ['is_on_trial', 'in_grace_period', 'days_until_renewal'];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function pendingPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'pending_plan_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function scopeServing(Builder $query): Builder
    {
        return $query->whereIn('status', SubscriptionStatus::servingValues());
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::Trialing->value
            && $this->trial_ends_at?->isFuture() === true;
    }

    public function inGracePeriod(): bool
    {
        return $this->status === SubscriptionStatus::PastDue->value
            && $this->grace_ends_at?->isFuture() === true;
    }

    public function hasSavedCard(): bool
    {
        return filled($this->payment_method_token);
    }

    /** When the paid (or trial) period runs out. */
    public function periodEndsAt(): ?Carbon
    {
        return $this->current_period_ends_at ?? $this->trial_ends_at;
    }

    /** The last moment the company keeps access, grace window included. */
    public function accessEndsAt(): ?Carbon
    {
        return $this->grace_ends_at ?? $this->periodEndsAt();
    }

    public function nextPeriodEndsAt(): Carbon
    {
        return BillingPeriod::nextEnd($this->billing_cycle, $this->periodEndsAt());
    }

    public function billingPeriodKey(?Carbon $periodEnd = null): string
    {
        return ($periodEnd ?? $this->nextPeriodEndsAt())->toDateString().':'.$this->billing_cycle;
    }

    public function reminderWasSent(string $key): bool
    {
        return in_array($key, (array) ($this->reminders_sent ?? []), true);
    }

    public function markReminderSent(string $key): void
    {
        $sent = array_values(array_unique(array_merge((array) ($this->reminders_sent ?? []), [$key])));

        // Keep the log bounded; only recent milestones matter for dedupe.
        $this->forceFill(['reminders_sent' => array_slice($sent, -25)])->save();
    }

    public function getIsOnTrialAttribute(): bool
    {
        return $this->isOnTrial();
    }

    public function getInGracePeriodAttribute(): bool
    {
        return $this->inGracePeriod();
    }

    public function getDaysUntilRenewalAttribute(): ?int
    {
        $end = $this->periodEndsAt();

        return $end ? (int) round(now()->startOfDay()->diffInDays($end->copy()->startOfDay(), false)) : null;
    }
}
