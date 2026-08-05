<?php

namespace App\Modules\Companies\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'plan',
        'timezone',
        'locale',
        'currency',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'settings' => 'array',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Suspended because a subscription lapsed rather than by an administrator.
     * These companies keep enough access to settle the bill and come back.
     */
    public function isBillingSuspended(): bool
    {
        return ! $this->is_active && filled(data_get($this->settings, 'billing.suspended_at'));
    }

    public function subscription(): HasOne
    {
        // `past_due` is included so a company keeps working while its failed
        // renewal is being retried inside the grace window.
        return $this->hasOne(CompanySubscription::class)
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->latestOfMany();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    public function latestSubscription(): HasOne
    {
        return $this->hasOne(CompanySubscription::class)->latestOfMany();
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }
}
