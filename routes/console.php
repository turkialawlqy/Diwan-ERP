<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Accounting automation (requires the scheduler: `* * * * * php artisan schedule:run`).
Schedule::command('erp:accounting-schedules recurring')->dailyAt('01:10')->withoutOverlapping()->onOneServer();
Schedule::command('erp:accounting-schedules depreciation')->monthlyOn(1, '02:10')->withoutOverlapping()->onOneServer();

// ZATCA: re-queue clearance / reporting that did not go through.
Schedule::command('erp:zatca-submit')->everyTenMinutes()->withoutOverlapping()->onOneServer();
