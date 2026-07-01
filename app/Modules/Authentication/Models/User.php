<?php

namespace App\Modules\Authentication\Models;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Models\Employee;
use App\Modules\Platform\Services\EffectiveAccessService;
use Database\Factories\UserFactory;
use BackedEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable {
        HasRoles::hasPermissionTo as protected spatieHasPermissionTo;
    }

    protected $fillable = [
        'company_id',
        'custom_role_id',
        'name',
        'email',
        'password',
        'must_change_password',
        'is_active',
        'last_activity_at',
        'deactivated_at',
        'last_login_at',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'must_change_password' => 'boolean',
            'is_active' => 'boolean',
            'last_activity_at' => 'datetime',
            'deactivated_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CompanyCustomRole::class, 'custom_role_id');
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function trustedLoginDevices(): HasMany
    {
        return $this->hasMany(TrustedLoginDevice::class);
    }

    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    public function socialAccounts(): HasMany
    {
        return $this->hasMany(UserSocialAccount::class);
    }

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin->value);
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $permissionName = $this->permissionName($permission);

        if ($permissionName === null) {
            return $this->spatieHasPermissionTo($permission, $guardName);
        }

        return app(EffectiveAccessService::class)->can($this, $permissionName);
    }

    public function can($abilities, $arguments = []): bool
    {
        if (is_array($abilities)) {
            return collect($abilities)->every(fn ($ability) => $this->can($ability, $arguments));
        }

        if (is_string($abilities) && str_contains($abilities, '.') && empty($arguments)) {
            return app(EffectiveAccessService::class)->can($this, $abilities);
        }

        return parent::can($abilities, $arguments);
    }

    private function permissionName($permission): ?string
    {
        if ($permission instanceof BackedEnum) {
            return (string) $permission->value;
        }

        if ($permission instanceof PermissionContract) {
            return $permission->name;
        }

        return is_string($permission) ? $permission : null;
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->employee?->phone;
    }
}
