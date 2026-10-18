<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Run by `php artisan schedule:run` (cron) or `php artisan schedule:work` (local).
Schedule::command('nightguardian:digest')->everyMinute()->withoutOverlapping();
Schedule::command('nightguardian:thresholds')->everyMinute()->withoutOverlapping();
Schedule::command('nightguardian:prune')->dailyAt('03:30')->withoutOverlapping();
