<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ciinlv:inventory-reconcile')
    ->dailyAt('02:00')
    ->withoutOverlapping(30);

Schedule::command('ciinlv:backup-health')
    ->hourly()
    ->withoutOverlapping(10);

Schedule::command('ciinlv:scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::command('marketplace:release-expired-reservations')
    ->everyMinute()
    ->withoutOverlapping(5);
