<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Warmup Engine Scheduled Commands
|--------------------------------------------------------------------------
*/

// Daily planner: runs once per day at 6 AM (plans all campaigns for the day)
Schedule::call(function () {
    app(\App\Services\DailyPlannerService::class)->planAllCampaigns();
})->dailyAt('06:00')->name('warmup:daily-plan')->withoutOverlapping();

// Scheduler: processes due events every 2 minutes
Schedule::call(function () {
    app(\App\Services\SchedulerService::class)->processEvents(20);
})->everyTwoMinutes()->name('warmup:process-events')->withoutOverlapping();

// Health updates: run daily at midnight
Schedule::call(function () {
    $senders = \App\Models\SenderMailbox::where('status', 'active')->get();
    $healthService = app(\App\Services\HealthService::class);
    foreach ($senders as $sender) {
        $healthService->updateDailyHealth($sender);
    }
})->dailyAt('23:55')->name('warmup:health-update');

// Domain DNS checks: run weekly on Monday
Schedule::call(function () {
    $domainService = app(\App\Services\DomainService::class);
    $domains = \App\Models\Domain::all();
    foreach ($domains as $domain) {
        $domainService->runDnsCheck($domain);
    }
})->weeklyOn(1, '03:00')->name('warmup:dns-check');
