<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

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
    try {
        app(\App\Services\DailyPlannerService::class)->planAllCampaigns();
        \App\Models\SystemSetting::set('last_planner_run', now()->toDateTimeString(), 'cron');
    } catch (\Throwable $e) {
        Log::error('Warmup daily planner failed: ' . $e->getMessage(), ['exception' => $e]);
    }
})->dailyAt('06:00')->name('warmup:daily-plan')->withoutOverlapping();

// Scheduler: processes due events every 2 minutes
Schedule::call(function () {
    try {
        app(\App\Services\SchedulerService::class)->processEvents(20);
        \App\Models\SystemSetting::set('last_scheduler_run', now()->toDateTimeString(), 'cron');
    } catch (\Throwable $e) {
        Log::error('Warmup scheduler failed: ' . $e->getMessage(), ['exception' => $e]);
    }
})->everyTwoMinutes()->name('warmup:process-events')->withoutOverlapping();

// Health updates: run daily at midnight
Schedule::call(function () {
    try {
        $senders = \App\Models\SenderMailbox::where('status', 'active')->get();
        $healthService = app(\App\Services\HealthService::class);
        foreach ($senders as $sender) {
            try {
                $healthService->updateDailyHealth($sender);
            } catch (\Throwable $e) {
                Log::warning('Health update failed for sender #' . $sender->id . ': ' . $e->getMessage());
            }
        }
        \App\Models\SystemSetting::set('last_health_run', now()->toDateTimeString(), 'cron');
    } catch (\Throwable $e) {
        Log::error('Warmup health update failed: ' . $e->getMessage(), ['exception' => $e]);
    }
})->dailyAt('23:55')->name('warmup:health-update');

// Domain DNS checks: run weekly on Monday
Schedule::call(function () {
    try {
        $domainService = app(\App\Services\DomainService::class);
        $domains = \App\Models\Domain::all();
        foreach ($domains as $domain) {
            try {
                $domainService->checkDns($domain);
            } catch (\Throwable $e) {
                Log::warning('DNS check failed for domain ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }
        \App\Models\SystemSetting::set('last_dns_run', now()->toDateTimeString(), 'cron');
    } catch (\Throwable $e) {
        Log::error('Warmup DNS check failed: ' . $e->getMessage(), ['exception' => $e]);
    }
})->weeklyOn(1, '03:00')->name('warmup:dns-check');
