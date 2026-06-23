<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\LoginAttemptRepository;
use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\DTOs\LoginData;
use App\Modules\Authentication\DTOs\RegisterData;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\TrustedLoginDevice;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Services\CompanyService;
use App\Modules\Finance\Services\FinanceSetupService;
use App\Modules\Inventory\Services\InventorySetupService;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Models\SubscriptionPlan;
use App\Modules\Subscriptions\Services\CompanySubscriptionService;
use App\Modules\Subscriptions\Services\SubscriptionPaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

class AuthenticationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly RoleRepository $roles,
        private readonly CompanyService $companies,
        private readonly CompanySubscriptionService $subscriptions,
        private readonly SubscriptionPaymentService $payments,
        private readonly FinanceSetupService $financeSetup,
        private readonly InventorySetupService $inventorySetup,
    ) {}

    public function register(RegisterData $data): array
    {
        $token = Str::random(48);
        $user = $this->createCompanyAdmin($data, false);
        $plan = SubscriptionPlan::where('slug', $data->planSlug)->where('is_active', true)->firstOrFail();
        $payment = $this->payments->createRegistrationPayment($user, $plan, $data->billingCycle, $token);

        return [
            'checkout' => [
                'reference' => $payment->reference,
                'registration_token' => $token,
                'checkout_url' => $payment->checkout_url,
                'status' => $payment->status,
            ],
            'company' => $payment->company,
            'user' => $this->users->loadProfile($user),
            'plan' => $payment->plan,
            'payment' => $payment,
        ];
    }

    public function createCompanyAdmin(RegisterData $data, bool $active = true): User
    {
        return DB::transaction(function () use ($data, $active) {
            $company = $this->companies->create(
                new CreateCompanyData(
                    $data->companyName,
                    config('app.timezone'),
                    config('app.locale'),
                    'EGP',
                ),
                $data->planSlug,
                $active,
            );

            $user = $this->users->create([
                'company_id' => $company->id,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);

            $this->roles->assign($user, UserRole::CompanyAdmin->value);
            if ($active) {
                $this->subscriptions->start(
                    $company,
                    new SubscribeData($data->planSlug, $data->billingCycle),
                    ['source' => 'registration', 'subscribed_by_user_id' => $user->id],
                );
                $this->financeSetup->provision($company);
                $this->inventorySetup->provision($company);
            }

            return $this->users->loadProfile($user);
        });
    }

    public function login(LoginData $data): array
    {
        $user = $this->users->findByEmail($data->email);
        $credentialsAreValid = $user !== null && Hash::check($data->password, $user->password);
        $accountIsActive = $user !== null
            && $user->is_active === true
            && ($user->isSuperAdmin() || $user->company?->is_active === true);
        $success = $credentialsAreValid && $accountIsActive;

        $this->loginAttempts->record([
            'user_id' => $user?->id,
            'email' => $data->email,
            'successful' => $success,
            'ip_address' => $data->ipAddress,
            'user_agent' => $data->userAgent,
        ]);

        if (! $success) {
            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        $this->verifyTwoFactor($user, $data);

        // A new login starts a new inactivity window. Otherwise an old
        // last_activity_at value can immediately invalidate the fresh token.
        $user->forceFill(['last_activity_at' => now(), 'last_login_at' => now()])->saveQuietly();

        return $this->tokenResponse($user, $data->deviceName);
    }

    public function profile(User $user): User
    {
        return $this->users->loadProfile($user);
    }

    public function logout(User $user): void
    {
        $user->currentAccessToken()?->delete();
    }

    public function logoutAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function changePassword(User $user, string $currentPassword, string $password): User
    {
        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $user->forceFill([
            'password' => Hash::make($password),
            'must_change_password' => false,
        ])->save();

        return $this->users->loadProfile($user);
    }

    public function tokenResponse(User $user, string $deviceName): array
    {
        return [
            'user' => $this->users->loadProfile($user),
            'token' => $user->createToken($deviceName)->plainTextToken,
        ];
    }

    private function verifyTwoFactor(User $user, LoginData $data): void
    {
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return;
        }

        $fingerprint = $this->deviceFingerprint($data);
        $trustedDevice = TrustedLoginDevice::query()
            ->where('user_id', $user->id)
            ->where('fingerprint_hash', $fingerprint)
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->first();

        if ($trustedDevice && ! $data->twoFactorCode && ! $data->recoveryCode) {
            $trustedDevice->forceFill([
                'ip_address' => $data->ipAddress,
                'last_used_at' => now(),
                'expires_at' => now()->addDays(90),
            ])->save();

            return;
        }

        $validCode = $data->twoFactorCode && app(TwoFactorAuthenticationProvider::class)->verify(
            Fortify::currentEncrypter()->decrypt($user->two_factor_secret),
            $data->twoFactorCode
        );
        $recoveryCode = $data->recoveryCode ?: $data->twoFactorCode;
        $validRecovery = $recoveryCode && in_array($recoveryCode, $user->recoveryCodes(), true);

        if (! $validCode && ! $validRecovery) {
            throw ValidationException::withMessages(['two_factor_code' => ['A valid two-factor or recovery code is required for this device.']]);
        }

        if ($validRecovery) {
            $user->replaceRecoveryCode($recoveryCode);
        }

        TrustedLoginDevice::updateOrCreate(
            ['user_id' => $user->id, 'fingerprint_hash' => $fingerprint],
            [
                'device_name' => $data->deviceName,
                'ip_address' => $data->ipAddress,
                'last_used_at' => now(),
                'expires_at' => now()->addDays(90),
            ],
        );
    }

    private function deviceFingerprint(LoginData $data): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower($data->deviceName),
            mb_strtolower($data->userAgent ?: 'unknown-agent'),
        ]));
    }
}
