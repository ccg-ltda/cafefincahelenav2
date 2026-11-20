<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Audit trail: drop old access rows every day (administrative actions are never pruned).
Schedule::command('activity:prune')->dailyAt('03:30');

// Emails: the queue is the database; this drains it every minute (no supervisor needed on shared hosting). The job's own $tries and
// backoff() decide the retries, not --tries=1 here. --max-time keeps one run shorter than the minute.
Schedule::command('queue:work --stop-when-empty --max-time=55 --tries=1')->everyMinute()->withoutOverlapping()->runInBackground();

// Unpaid orders: cancel the expired ones (asks Wompi first; never cancels when Wompi cannot be asked).
Schedule::command('orders:expire-unpaid')->everyFiveMinutes()->withoutOverlapping();
