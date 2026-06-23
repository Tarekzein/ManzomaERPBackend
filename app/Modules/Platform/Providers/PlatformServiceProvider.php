<?php

namespace App\Modules\Platform\Providers;

use App\Modules\Platform\Contracts\TranslationProvider;
use App\Modules\Platform\Services\LibreTranslateProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class PlatformServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TranslationProvider::class, function () {
            abort_unless(config('services.translation.driver') === 'libretranslate', 500, 'Unsupported translation driver.');

            return new LibreTranslateProvider;
        });
    }

    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api')
            ->group(__DIR__.'/../Routes/api.php');
    }
}
