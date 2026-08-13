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

Schedule::command('pos:dispatch-outbox')
    ->everyMinute()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:pos-outbox', now()->toISOString(), now()->addMinutes(10)));

Schedule::command('platform:retry-webhooks')
    ->everyMinute()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:webhooks', now()->toISOString(), now()->addMinutes(10)));

Schedule::command('meta:retry-events')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:meta-events', now()->toISOString(), now()->addMinutes(10)));

Schedule::command('meta:sync-audiences')
    ->hourly()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:meta-audiences', now()->toISOString(), now()->addMinutes(10)));

// Meta long-lived tokens last ~60 days and cannot be refreshed once expired,
// so this has to run well ahead of the deadline.
Schedule::command('meta:maintain-connections')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:meta-connections', now()->toISOString(), now()->addHours(25)));

Schedule::command('tiktok:maintain-connections')
    ->dailyAt('04:45')
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:tiktok-connections', now()->toISOString(), now()->addHours(25)));

// TikTok builds lead exports asynchronously: one pass requests, the next collects.
Schedule::command('tiktok:sync-leads')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:tiktok-leads', now()->toISOString(), now()->addMinutes(30)));

Schedule::command('tiktok:sync-audiences')
    ->hourly()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:tiktok-audiences', now()->toISOString(), now()->addMinutes(90)));

Schedule::command('tiktok:retry-events')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:tiktok-events', now()->toISOString(), now()->addMinutes(10)));

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

Schedule::command('organizations:reconcile-quotas')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->after(fn () => Cache::put('system_health:scheduler:organization-quotas', now()->toISOString(), now()->addHours(25)));
