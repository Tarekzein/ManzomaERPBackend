<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AuthenticationServiceProvider::class,
    HorizonServiceProvider::class,
];
