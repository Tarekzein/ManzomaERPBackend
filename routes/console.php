<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Schedule::command('reporting:run-schedules')->everyMinute()->withoutOverlapping();
Schedule::command('notifications:send-due')->everyMinute()->withoutOverlapping();
