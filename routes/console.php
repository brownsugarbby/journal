<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
|
| Run by one cron line on the server (see docs/DEPLOY.md):
|   * * * * * cd /var/www/journal-system && php artisan schedule:run >> /dev/null 2>&1
|
| Times are Asia/Jakarta (APP_TIMEZONE). The audit log is never pruned: it is
| the history of who entered, edited and voided what.
|
*/

// Nightly encrypted backup of the database and uploaded files.
Schedule::command('backup:clean')->dailyAt('01:30')->withoutOverlapping();
Schedule::command('backup:run')->dailyAt('02:00')->withoutOverlapping()->onOneServer();
Schedule::command('backup:monitor')->dailyAt('09:00');
