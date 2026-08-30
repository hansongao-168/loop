<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Automation schedule
|--------------------------------------------------------------------------
|
| Driven by a single cron entry:
|
|   * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
|
| 1. loop:health keeps LOOP provider health fresh so the LoopRouter
|    automatically prefers healthy providers when switching models.
| 2. The queue drain processes pending document indexing and graph
|    community builds without a long-running worker daemon, which
|    suits the Apache + brew services deployment on this machine.
|
*/

Schedule::command('loop:health')->everyFiveMinutes()->withoutOverlapping();

Schedule::command('queue:work --stop-when-empty --max-time=89 --sleep=1')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();
