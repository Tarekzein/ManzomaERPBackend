<?php

namespace App\Modules\Authentication\Models;

use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Companies\Models\Company;
use App\Modules\HR\Models\Employee;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

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

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(UserRole::SuperAdmin->value);
    }

    public function routeNotificationForSms(): ?string
    {
        return $this->employee?->phone;
    }
}
