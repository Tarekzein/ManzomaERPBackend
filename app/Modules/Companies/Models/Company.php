<?php

namespace App\Modules\Companies\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Company extends Model
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'name',
        'slug',
        'plan',
        'timezone',
        'locale',
        'currency',
        'is_active',
        'archived_at',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'archived_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        // Every company gets a workspace slug; it is what the app URLs are
        // built from (/app/{slug}/…).
        static::creating(function (Company $company) {
            $company->slug = $company->slug ?: self::uniqueSlug($company->name);
        });
    }

    public static function uniqueSlug(?string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $name) ?: 'workspace';
        $slug = $base;
        $suffix = 2;

        while (self::query()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    /**
     * Slug for the app URLs, falling back to the id for rows created before
     * slugs existed. API routes still bind companies by id.
     */
    public function workspaceKey(): string
    {
        return $this->slug ?: (string) $this->getKey();
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'company_memberships')
            ->withPivot([
                'organization_id',
                'role_id',
                'custom_role_id',
                'status',
                'joined_at',
                'invited_by_user_id',
                'suspended_at',
            ])
            ->withTimestamps();
    }

    /**
     * Suspended because a subscription lapsed rather than by an administrator.
     * These companies keep enough access to settle the bill and come back.
     */
    public function isBillingSuspended(): bool
    {
        $this->loadMissing('organization');

        if ($this->organization) {
            return $this->organization->billing_suspended_at !== null;
        }

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
