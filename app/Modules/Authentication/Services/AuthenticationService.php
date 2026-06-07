<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Contracts\LoginAttemptRepository;
use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\DTOs\LoginData;
use App\Modules\Authentication\DTOs\RegisterData;
use App\Modules\Authentication\Enums\UserRole;
use App\Modules\Authentication\Models\User;
use App\Modules\Companies\DTOs\CreateCompanyData;
use App\Modules\Companies\Services\CompanyService;
use App\Modules\Subscriptions\DTOs\SubscribeData;
use App\Modules\Subscriptions\Services\CompanySubscriptionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthenticationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly LoginAttemptRepository $loginAttempts,
        private readonly RoleRepository $roles,
        private readonly CompanyService $companies,
        private readonly CompanySubscriptionService $subscriptions,
    ) {}

    public function register(RegisterData $data): array
    {
        $user = $this->createCompanyAdmin($data);

        return $this->tokenResponse($user, $data->deviceName);
    }

    public function createCompanyAdmin(RegisterData $data): User
    {
        return DB::transaction(function () use ($data) {
            $company = $this->companies->create(
                new CreateCompanyData(
                    $data->companyName,
                    config('app.timezone'),
                    config('app.locale'),
                    'EGP',
                ),
                $data->planSlug,
            );

            $user = $this->users->create([
                'company_id' => $company->id,
                'name' => $data->name,
                'email' => $data->email,
                'password' => Hash::make($data->password),
            ]);

            $this->roles->assign($user, UserRole::CompanyAdmin->value);
            $this->subscriptions->start(
                $company,
                new SubscribeData($data->planSlug, $data->billingCycle),
                ['source' => 'registration', 'subscribed_by_user_id' => $user->id],
            );

            return $this->users->loadProfile($user);
        });
    }

    public function login(LoginData $data): array
    {
        $user = $this->users->findByEmail($data->email);
        $credentialsAreValid = $user !== null && Hash::check($data->password, $user->password);
        $accountIsActive = $user !== null && ($user->isSuperAdmin() || $user->company?->is_active === true);
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

    private function tokenResponse(User $user, string $deviceName): array
    {
        return [
            'user' => $this->users->loadProfile($user),
            'token' => $user->createToken($deviceName)->plainTextToken,
        ];
    }
}
