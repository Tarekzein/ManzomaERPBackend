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

Schedule::command('meta:retry-events')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:meta-events', now()->toISOString(), now()->addMinutes(10)));

Schedule::command('meta:sync-audiences')
    ->hourly()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:meta-audiences', now()->toISOString(), now()->addMinutes(10)));

// Renewals run hourly so a failed charge is retried and grace windows close on
// time; reminders only need one pass a day.
Schedule::command('subscriptions:process-renewals')
    ->hourly()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:subscription-renewals', now()->toISOString(), now()->addMinutes(90)));

Schedule::command('subscriptions:send-reminders')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:subscription-reminders', now()->toISOString(), now()->addHours(25)));
