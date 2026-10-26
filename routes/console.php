<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Accounting automation (requires the scheduler: `* * * * * php artisan schedule:run`).
Schedule::command('erp:accounting-schedules recurring')->dailyAt('01:10')->withoutOverlapping()->onOneServer();
Schedule::command('erp:accounting-schedules depreciation')->monthlyOn(1, '02:10')->withoutOverlapping()->onOneServer();

// ZATCA: re-queue clearance / reporting that did not go through.
Schedule::command('erp:zatca-submit')->everyTenMinutes()->withoutOverlapping()->onOneServer();

// HR: end-of-service provision at the start of each month for the previous month end.
Schedule::command('erp:hr-provision')->monthlyOn(1, '03:10')->withoutOverlapping()->onOneServer();

// Daily business alerts (overdue invoices, expiring documents, approvals...).
Schedule::command('erp:alerts')->dailyAt('06:50')->withoutOverlapping()->onOneServer();

// Encrypted backups: daily at 02:40, restore test every Sunday.
Schedule::command('erp:backup')->dailyAt('02:40')->withoutOverlapping()->onOneServer();
Schedule::command('erp:backup --verify')->weeklyOn(0, '04:20')->withoutOverlapping()->onOneServer();

// Heartbeat read by /health and erp:preflight: proves the scheduler is running.
Schedule::call(fn () => Cache::put('scheduler:heartbeat', now()->timestamp, 3600))->everyMinute()->name('scheduler-heartbeat')->onOneServer();
