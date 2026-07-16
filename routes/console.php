<?php

use App\Console\MarketplaceScheduleRegistrar;
use Illuminate\Console\Scheduling\Schedule as ConsoleSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('marketplace:run-due-schedules')
    ->everyMinute()
    ->withoutOverlapping();

MarketplaceScheduleRegistrar::register(app(ConsoleSchedule::class));
