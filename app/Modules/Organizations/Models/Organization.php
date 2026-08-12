<?php

namespace App\Modules\Organizations\Models;

use App\Modules\Authentication\Models\User;
use App\Modules\Companies\Models\Company;
use App\Modules\Subscriptions\Enums\SubscriptionStatus;
use App\Modules\Subscriptions\Models\CompanySubscription;
use App\Modules\Subscriptions\Models\SubscriptionPayment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Organization extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'slug',
        'name',
        'status',
        'billing_email',
        'timezone',
        'locale',
        'currency',
        'settings',
        'created_by_user_id',
        'billing_suspended_at',
        'archived_at',
    ];

    /**
     * Organization-level data is hidden by default so that embedding an
     * organization anywhere (a company relation, the session payload) cannot
     * leak it to a member who only holds a company workspace role.
     * OrganizationAccessService::project() reveals it to organization roles.
     *
     * @see \App\Modules\Organizations\Services\OrganizationAccessService::ORGANIZATION_ONLY_ATTRIBUTES
     */
    protected $hidden = [
        'billing_email',
        'settings',
        'created_by_user_id',
        'billing_suspended_at',
        'active_companies_count',
        'active_members_count',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'billing_suspended_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            $organization->slug = $organization->slug ?: self::uniqueSlug($organization->name);
        });
    }

    public static function uniqueSlug(?string $name, ?int $ignoreId = null): string
    {
        $base = Str::slug((string) $name) ?: 'organization';
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

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBindingQuery($query, $value, $field);
        }

        return $query->where(function ($query) use ($value) {
            $query->where('slug', $value);

            if (ctype_digit((string) $value)) {
                $query->orWhere($this->getKeyName(), (int) $value);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationMembership::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'organization_memberships')
            ->withPivot(['role', 'status', 'joined_at', 'invited_by_user_id', 'suspended_at'])
            ->withTimestamps();
    }

    public function companyMemberships(): HasMany
    {
        return $this->hasMany(CompanyMembership::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(OrganizationInvitation::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(CompanySubscription::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(CompanySubscription::class)
            ->whereIn('status', SubscriptionStatus::servingValues())
            ->latestOfMany();
    }

    public function subscriptionPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }
}
