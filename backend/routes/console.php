<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Order deadlines: farm answers, payment windows, abandoned payment prompts and dispute windows.
Schedule::command('orders:process-deadlines')->everyMinute()->withoutOverlapping();
