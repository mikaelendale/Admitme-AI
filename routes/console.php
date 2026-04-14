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
