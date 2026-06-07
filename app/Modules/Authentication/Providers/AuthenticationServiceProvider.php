<?php

namespace App\Modules\Authentication\Providers;

use App\Modules\Authentication\Actions\Fortify\CreateNewUser;
use App\Modules\Authentication\Actions\Fortify\ResetUserPassword;
use App\Modules\Authentication\Actions\Fortify\UpdateUserPassword;
use App\Modules\Authentication\Actions\Fortify\UpdateUserProfileInformation;
use App\Modules\Authentication\Contracts\CompanyRepository;
use App\Modules\Authentication\Contracts\LoginAttemptRepository;
use App\Modules\Authentication\Contracts\RoleRepository;
use App\Modules\Authentication\Contracts\UserRepository;
use App\Modules\Authentication\Repositories\EloquentCompanyRepository;
use App\Modules\Authentication\Repositories\EloquentLoginAttemptRepository;
use App\Modules\Authentication\Repositories\EloquentUserRepository;
use App\Modules\Authentication\Repositories\SpatieRoleRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable;
use Laravel\Fortify\Fortify;

class AuthenticationServiceProvider extends ServiceProvider
{
    public array $bindings = [
        UserRepository::class => EloquentUserRepository::class,
        CompanyRepository::class => EloquentCompanyRepository::class,
        LoginAttemptRepository::class => EloquentLoginAttemptRepository::class,
        RoleRepository::class => SpatieRoleRepository::class,
    ];

    public function boot(): void
    {
        $this->configureFortify();

        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../Routes/api.php');
    }

    private function configureFortify(): void
    {
        Fortify::createUsersUsing(CreateNewUser::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::redirectUserForTwoFactorAuthenticationUsing(RedirectIfTwoFactorAuthenticatable::class);

        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by($request->session()->get('login.id')));
        RateLimiter::for('passkeys', fn (Request $request) => Limit::perMinute(10)->by(
            ($request->input('credential.id') ?: $request->session()->getId()).'|'.$request->ip()
        ));
    }
}
