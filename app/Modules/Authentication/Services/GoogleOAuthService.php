<?php

namespace App\Modules\Authentication\Services;

use App\Modules\Authentication\Models\User;
use App\Modules\Authentication\Models\UserSocialAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class GoogleOAuthService
{
    private const PROVIDER = 'google';

    private const STATE_PREFIX = 'oauth.google.';

    public function __construct(private readonly AuthenticationService $authentication) {}

    public function authorizationUrl(string $intent, ?User $user = null): array
    {
        if (! in_array($intent, ['login', 'link'], true)) {
            throw ValidationException::withMessages(['intent' => ['Unsupported Google OAuth intent.']]);
        }

        if ($intent === 'link' && ! $user) {
            throw ValidationException::withMessages(['intent' => ['A signed-in user is required to link Google.']]);
        }

        $state = Str::random(48);
        Cache::put(self::STATE_PREFIX.$state, [
            'intent' => $intent,
            'user_id' => $user?->id,
        ], now()->addMinutes(10));

        return [
            'url' => 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
                'client_id' => $this->config('client_id'),
                'redirect_uri' => $this->redirectUri(),
                'response_type' => 'code',
                'scope' => 'openid email profile',
                'state' => $state,
                'prompt' => 'select_account',
            ]),
            'state' => $state,
            'expires_in' => 600,
        ];
    }

    public function handleCallback(string $code, string $state, string $deviceName = 'ManzomaERP Web'): array
    {
        $payload = Cache::pull(self::STATE_PREFIX.$state);
        if (! $payload) {
            throw ValidationException::withMessages(['state' => ['The Google sign-in session has expired.']]);
        }

        $profile = $this->fetchProfile($code);

        return match ($payload['intent']) {
            'link' => $this->linkAccount((int) $payload['user_id'], $profile),
            'login' => $this->loginWithAccount($profile, $deviceName),
            default => throw ValidationException::withMessages(['state' => ['Unsupported Google sign-in session.']]),
        };
    }

    public function unlink(User $user): User
    {
        $user->socialAccounts()->where('provider', self::PROVIDER)->delete();

        return $this->authentication->profile($user->refresh());
    }

    private function linkAccount(int $userId, array $profile): array
    {
        $user = User::findOrFail($userId);
        $existing = UserSocialAccount::where('provider', self::PROVIDER)
            ->where('provider_user_id', $profile['sub'])
            ->first();

        if ($existing && $existing->user_id !== $user->id) {
            throw ValidationException::withMessages([
                'google' => ['This Google account is already connected to another ManzomaERP user.'],
            ]);
        }

        $this->linkGoogleToUser($user, $profile);

        return [
            'linked' => true,
            'user' => $this->authentication->profile($user->refresh()),
        ];
    }

    private function linkGoogleToUser(User $user, array $profile): void
    {
        $existingForUser = $user->socialAccounts()->where('provider', self::PROVIDER)->first();
        if ($existingForUser && $existingForUser->provider_user_id !== $profile['sub']) {
            throw ValidationException::withMessages([
                'google' => ['This ManzomaERP user is already connected to another Google account.'],
            ]);
        }

        $user->socialAccounts()->updateOrCreate(
            ['provider' => self::PROVIDER],
            [
                'provider_user_id' => $profile['sub'],
                'email' => $profile['email'] ?? null,
                'name' => $profile['name'] ?? null,
                'avatar' => $profile['picture'] ?? null,
                'linked_at' => now(),
            ],
        );
    }

    private function loginWithAccount(array $profile, string $deviceName): array
    {
        $account = UserSocialAccount::with('user.company', 'user.roles')
            ->where('provider', self::PROVIDER)
            ->where('provider_user_id', $profile['sub'])
            ->first();

        if (! $account) {
            $user = User::with('company', 'roles')
                ->where('email', $profile['email'])
                ->first();

            if (! $user) {
                return [
                    'requires_registration' => true,
                    'profile' => [
                        'name' => $profile['name'] ?? '',
                        'email' => $profile['email'],
                        'avatar' => $profile['picture'] ?? null,
                    ],
                ];
            }

            $this->linkGoogleToUser($user, $profile);
            $account = $user->socialAccounts()->where('provider', self::PROVIDER)->first();
        }

        $user = $account->user;
        if (! $user || $user->is_active !== true || (! $user->isSuperAdmin() && $user->company?->is_active !== true)) {
            throw ValidationException::withMessages(['google' => ['This ManzomaERP account is not active.']]);
        }

        $user->forceFill(['last_activity_at' => now(), 'last_login_at' => now()])->saveQuietly();

        return $this->authentication->tokenResponse($user, $deviceName);
    }

    private function fetchProfile(string $code): array
    {
        try {
            $token = Http::asForm()
                ->timeout(10)
                ->post('https://oauth2.googleapis.com/token', [
                    'client_id' => $this->config('client_id'),
                    'client_secret' => $this->config('client_secret'),
                    'redirect_uri' => $this->redirectUri(),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                ])
                ->throw()
                ->json();

            $profile = Http::withToken($token['access_token'])
                ->timeout(10)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo')
                ->throw()
                ->json();
        } catch (Throwable) {
            throw ValidationException::withMessages(['google' => ['Google sign-in could not be verified. Please try again.']]);
        }

        if (empty($profile['sub']) || empty($profile['email']) || ($profile['email_verified'] ?? false) !== true) {
            throw ValidationException::withMessages(['google' => ['Google did not return a verified email address.']]);
        }

        return $profile;
    }

    private function config(string $key): string
    {
        $value = config("services.google.{$key}");
        if (! $value) {
            throw ValidationException::withMessages(['google' => ['Google sign-in is not configured.']]);
        }

        return $value;
    }

    private function redirectUri(): string
    {
        return config('services.google.redirect_uri') ?: rtrim(config('app.url'), '/').'/auth/google/callback';
    }
}
