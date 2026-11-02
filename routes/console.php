<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('admitme:send-deadline-notifications')
    ->dailyAt('08:00')
    ->timezone('UTC');

Schedule::command('admitme:notify-expiring-passes')
    ->dailyAt('08:30')
    ->timezone('UTC');

// Phase 9: delete expired export archives and operational records past retention.
Schedule::command('admitme:prune-data')
    ->hourly()
    ->withoutOverlapping();

// Phase 11: log queue backlog, stuck work, stale shared sources, missing provider keys and storage problems.
Schedule::command('admitme:health --log')
    ->hourly()
    ->withoutOverlapping();

// Phase 7: pick up task steps whose worker stopped, and dispatch steps that became ready.
Schedule::command('admitme:tasks:recover')
    ->everyMinute()
    ->withoutOverlapping();
