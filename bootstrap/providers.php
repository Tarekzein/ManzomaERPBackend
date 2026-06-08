<?php

use App\Modules\Authentication\Providers\AuthenticationServiceProvider;
use App\Modules\Projects\Providers\ProjectsServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\HorizonServiceProvider;

return [
    AppServiceProvider::class,
    AuthenticationServiceProvider::class,
    ProjectsServiceProvider::class,
    HorizonServiceProvider::class,
];
