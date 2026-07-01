<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('reporting:run-schedules')
    ->everyMinute()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:reporting', now()->toISOString(), now()->addMinutes(10)));

Schedule::command('notifications:send-due')
    ->everyMinute()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:notifications', now()->toISOString(), now()->addMinutes(10)));
