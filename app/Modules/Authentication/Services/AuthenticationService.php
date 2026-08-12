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
use App\Modules\Organizations\Models\CompanyMembership;
use App\Modules\Organizations\Models\Organization;
use App\Modules\Organizations\Models\OrganizationMembership;
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
use Spatie\Permission\Models\Role;

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
        $plan = SubscriptionPlan::with('promotions')->where('slug', $data->planSlug)->where('is_active', true)->firstOrFail();

        if ($plan->trial_enabled && (int) $plan->trial_days > 0) {
            $activation = $this->payments->createTrialActivation($user, $plan, $data->billingCycle, $token);

            return [
                'activation_mode' => 'trial',
                'checkout' => [
                    'reference' => $activation['payment']->reference,
                    'registration_token' => $token,
                    'checkout_url' => null,
                    'status' => $activation['payment']->status,
                    'mode' => config('services.paymob.mode'),
                ],
                'trial' => [
                    'days' => (int) $plan->trial_days,
                    'ends_at' => $activation['subscription']->trial_ends_at?->toISOString(),
                ],
                'company' => $activation['payment']->company,
                'user' => $this->users->loadSessionProfile($user),
                'plan' => $activation['payment']->plan,
                'payment' => $activation['payment'],
                'subscription' => $activation['subscription'],
                'auth' => $this->tokenResponse($user, $data->deviceName),
            ];
        }

        $payment = $this->payments->createRegistrationPayment($user, $plan, $data->billingCycle, $token);

        return [
            'activation_mode' => 'payment',
            'checkout' => [
                'reference' => $payment->reference,
                'registration_token' => $token,
                'checkout_url' => $payment->checkout_url,
                'expires_at' => $payment->checkout_expires_at?->toISOString(),
                'status' => $payment->status,
                // "mock" tells the client the sandbox controls are available.
                'mode' => config('services.paymob.mode'),
                // Set when the gateway was unreachable; the client can retry
                // through POST /api/payments/{reference}/checkout.
                'error' => $payment->failure_reason,
            ],
            'company' => $payment->company,
            'user' => $this->users->loadSessionProfile($user),
            'plan' => $payment->plan,
            'payment' => $payment,
        ];
    }

    public function createCompanyAdmin(RegisterData $data, bool $active = true): User
    {
        return DB::transaction(function () use ($data, $active) {
            $organization = Organization::query()->create([
                'name' => $data->companyName,
                'status' => Organization::STATUS_ACTIVE,
                'billing_email' => $data->email,
                'timezone' => config('app.timezone'),
                'locale' => config('app.locale'),
                'currency' => 'EGP',
                'settings' => [],
            ]);
            $company = $this->companies->create(
                new CreateCompanyData(
                    $data->companyName,
                    config('app.timezone'),
                    config('app.locale'),
                    'EGP',
                ),
                $data->planSlug,
                $active,
                $organization,
            );

            $user = $this->users->create([
                'company_id' => $company->id,
                'default_company_id' => $company->id,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);

            $this->roles->assign($user, UserRole::CompanyAdmin->value);
            $organization->forceFill(['created_by_user_id' => $user->getKey()])->save();
            OrganizationMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'user_id' => $user->getKey(),
                'role' => OrganizationMembership::ROLE_OWNER,
                'status' => OrganizationMembership::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
            CompanyMembership::query()->create([
                'organization_id' => $organization->getKey(),
                'company_id' => $company->getKey(),
                'user_id' => $user->getKey(),
                'role_id' => Role::findByName(
                    UserRole::CompanyAdmin->value,
                    'web',
                )->getKey(),
                'status' => CompanyMembership::STATUS_ACTIVE,
                'joined_at' => now(),
            ]);
            if ($active) {
                $this->subscriptions->start(
                    $company,
                    new SubscribeData($data->planSlug, $data->billingCycle),
                    ['source' => 'registration', 'subscribed_by_user_id' => $user->id],
                );
                $this->financeSetup->provision($company);
                $this->inventorySetup->provision($company);
            }

            return $this->users->loadSessionProfile($user);
        });
    }

    public function login(LoginData $data): array
    {
        $user = $this->users->findByEmail($data->email);
        $credentialsAreValid = $user !== null && Hash::check($data->password, $user->password);
        // Suspension is not an authentication failure. A suspended account,
        // company or organization signs in normally and is then told why it is
        // blocked; EnforceCompanyAccess closes everything past the session
        // endpoints. Only an account with nowhere to belong at all is refused,
        // because there is nothing to show it.
        $accountIsActive = $user !== null
            && ($user->isSuperAdmin() || $this->belongsToATenant($user));
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
        return $this->users->loadSessionProfile($user);
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

        return $this->users->loadSessionProfile($user);
    }

    public function tokenResponse(User $user, string $deviceName): array
    {
        return [
            'user' => $this->users->loadSessionProfile($user),
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

    /**
     * An unpaid registration and an administratively suspended company must
     * not become login-capable merely because their organization membership
     * already exists. Organization-only members may still sign in to manage
     * the organization, and billing-suspended tenants may sign in to recover.
     */
    /**
     * Whether the account still belongs somewhere it could be shown a session.
     *
     * Deliberately blind to suspension: a suspended organization, company or
     * membership all still qualify, because the whole point is to sign the
     * user in and explain the suspension. Only archived organizations and
     * removed memberships leave an account with nothing at all.
     */
    private function belongsToATenant(User $user): bool
    {
        // Somewhere to land: a company that is active, one an admin suspended,
        // or one whose subscription lapsed — the last of those signs in
        // precisely so it can pay. An inactive company with none of those
        // marks is a registration still waiting on its first payment, and
        // stays unreachable until the subscription activates it.
        $usableCompany = fn ($query) => $query
            ->whereNull('archived_at')
            ->where(fn ($usable) => $usable
                ->where('is_active', true)
                ->orWhereNotNull('suspended_at')
                ->orWhereHas('organization', fn ($organization) => $organization
                    ->whereNotNull('billing_suspended_at'))
                ->orWhereNotNull('settings->billing->suspended_at'));

        $hasCompany = $user->companyMemberships()
            ->whereIn('status', [
                CompanyMembership::STATUS_ACTIVE,
                CompanyMembership::STATUS_SUSPENDED,
            ])
            ->whereHas('company', $usableCompany)
            ->exists();

        if ($hasCompany) {
            return true;
        }

        // An organization member with no workspace assigned yet still has a
        // session worth showing. Checked only when they hold no company
        // memberships at all, so an unpaid registration cannot slip through on
        // the owner membership it was created with.
        if (! $user->companyMemberships()->exists()
            && $user->organizationMemberships()
                ->whereIn('status', [
                    OrganizationMembership::STATUS_ACTIVE,
                    OrganizationMembership::STATUS_SUSPENDED,
                ])
                ->whereHas('organization', fn ($query) => $query
                    ->where('status', '!=', Organization::STATUS_ARCHIVED)
                    ->whereNull('archived_at'))
                ->exists()) {
            return true;
        }

        // Rows not yet dual-written to memberships still resolve through the
        // legacy company relation.
        return $user->company !== null
            && ($user->company->is_active === true
                || $user->company->suspended_at !== null
                || $user->company->isBillingSuspended());
    }

    private function deviceFingerprint(LoginData $data): string
    {
        return hash('sha256', implode('|', [
            mb_strtolower($data->deviceName),
            mb_strtolower($data->userAgent ?: 'unknown-agent'),
        ]));
    }
}
