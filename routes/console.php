<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\TripReminderSender;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(fn () => app(TripReminderSender::class)->sendForTomorrow())
    ->dailyAt('09:00')
    ->name('send-trip-reminders')
    ->withoutOverlapping();
