<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Log;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('warmup:seed-templates', function () {
    $this->info('Seeding niche warmup templates...');
    (new \Database\Seeders\NicheTemplateSeeder())->run();
    $this->info('Done!');
})->purpose('Seed 200 niche spintax warmup templates');

Artisan::command('warmup:run-diagnostic', function () {
    $this->info('Running system diagnostic...');
    $snapshot = app(\App\Services\SystemDiagnosticService::class)->createDailySnapshot();
    $this->info("Status: {$snapshot->overall_status} | Events: {$snapshot->events_completed}/{$snapshot->events_planned} | Health: {$snapshot->avg_health_score}");
})->purpose('Run system diagnostic and create daily snapshot');

/*
|--------------------------------------------------------------------------
| Warmup Engine Scheduled Commands
|--------------------------------------------------------------------------
*/

// Daily planner: runs once per day at 6 AM (plans all campaigns for the day)
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
    try {
        app(\App\Services\DailyPlannerService::class)->planAllCampaigns();
        \App\Models\SystemSetting::set('last_planner_run', now()->toDateTimeString(), 'cron');
        $diagnostic->recordHeartbeat('warmup:daily-plan', true, null, 1440);
    } catch (\Throwable $e) {
        Log::error('Warmup daily planner failed: ' . $e->getMessage(), ['exception' => $e]);
        $diagnostic->recordHeartbeat('warmup:daily-plan', false, $e->getMessage(), 1440);
    }
})->dailyAt('06:00')->name('warmup:daily-plan')->withoutOverlapping();

// Scheduler: processes due events every 2 minutes
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
    try {
        app(\App\Services\SchedulerService::class)->processEvents(20);
        \App\Models\SystemSetting::set('last_scheduler_run', now()->toDateTimeString(), 'cron');
        $diagnostic->recordHeartbeat('warmup:process-events', true, null, 2);
    } catch (\Throwable $e) {
        Log::error('Warmup scheduler failed: ' . $e->getMessage(), ['exception' => $e]);
        $diagnostic->recordHeartbeat('warmup:process-events', false, $e->getMessage(), 2);
    }
})->everyTwoMinutes()->name('warmup:process-events')->withoutOverlapping();

// Health updates: run daily at midnight
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
    try {
        $senders = \App\Models\SenderMailbox::where('status', 'active')->get();
        $healthService = app(\App\Services\HealthService::class);
        $safetyService = app(\App\Services\SafetyService::class);
        $failedCount = 0;
        foreach ($senders as $sender) {
            try {
                $healthService->updateDailyHealth($sender);
                $safetyService->checkDeliverabilityThresholds($sender);
                $safetyService->evaluateDailyRecovery($sender);
            } catch (\Throwable $e) {
                Log::warning('Health update failed for sender #' . $sender->id . ': ' . $e->getMessage());
                $failedCount++;
            }
        }
        if ($failedCount > 0 && $failedCount > $senders->count() * 0.1) {
            \App\Models\SystemAlert::create([
                'title' => 'Health Update Batch Failure',
                'message' => "{$failedCount}/{$senders->count()} senders failed health update. Check logs for details.",
                'severity' => $failedCount > $senders->count() * 0.5 ? 'critical' : 'warning',
                'context_type' => 'system',
                'context_id' => null,
            ]);
        }
        \App\Models\SystemSetting::set('last_health_run', now()->toDateTimeString(), 'cron');
        $diagnostic->recordHeartbeat('warmup:health-update', true, null, 1440);
    } catch (\Throwable $e) {
        Log::error('Warmup health update failed: ' . $e->getMessage(), ['exception' => $e]);
        $diagnostic->recordHeartbeat('warmup:health-update', false, $e->getMessage(), 1440);
    }
})->dailyAt('23:55')->name('warmup:health-update');

// Domain DNS checks: run weekly on Monday
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
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
        $diagnostic->recordHeartbeat('warmup:dns-check', true, null, 10080);
    } catch (\Throwable $e) {
        Log::error('Warmup DNS check failed: ' . $e->getMessage(), ['exception' => $e]);
        $diagnostic->recordHeartbeat('warmup:dns-check', false, $e->getMessage(), 10080);
    }
})->weeklyOn(1, '03:00')->name('warmup:dns-check');

// Queue worker: process queued warmup jobs every minute
Schedule::command('queue:work database --queue=warmup --stop-when-empty --max-time=50')
    ->everyMinute()
    ->name('warmup:queue-worker')
    ->withoutOverlapping();

// ── Phase H: New Scheduled Tasks ──

// Seed health check: run daily at 23:00
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
    try {
        $results = app(\App\Services\SeedHealthService::class)->checkAllSeeds();
        Log::info("[SeedHealth] Checked: {$results['checked']}, Disabled: {$results['disabled']}, Warnings: {$results['warnings']}");
        $diagnostic->recordHeartbeat('warmup:seed-health', true, null, 1440);
    } catch (\Throwable $e) {
        Log::error('Seed health check failed: ' . $e->getMessage());
        $diagnostic->recordHeartbeat('warmup:seed-health', false, $e->getMessage(), 1440);
    }
})->dailyAt('23:00')->name('warmup:seed-health');

// Daily diagnostic snapshot: run at 23:30
Schedule::call(function () {
    try {
        $snapshot = app(\App\Services\SystemDiagnosticService::class)->createDailySnapshot();
        Log::info("[Diagnostic] Daily snapshot: {$snapshot->overall_status}");
    } catch (\Throwable $e) {
        Log::error('Daily diagnostic failed: ' . $e->getMessage());
    }
})->dailyAt('23:30')->name('warmup:daily-diagnostic');

// Cron watchdog: check heartbeats every 30 minutes
Schedule::call(function () {
    try {
        app(\App\Services\SystemDiagnosticService::class)->checkCronHealth();
    } catch (\Throwable $e) {
        Log::error('Cron watchdog failed: ' . $e->getMessage());
    }
})->everyThirtyMinutes()->name('warmup:cron-watchdog');

// Fix stuck events: run every 15 minutes
Schedule::call(function () {
    try {
        $fixed = app(\App\Services\SystemDiagnosticService::class)->fixStuckEvents();
        if ($fixed > 0) Log::info("[Diagnostic] Auto-fixed {$fixed} stuck events");
    } catch (\Throwable $e) {
        Log::error('Fix stuck events failed: ' . $e->getMessage());
    }
})->everyFifteenMinutes()->name('warmup:fix-stuck');

// Content fingerprint cleanup: run weekly on Sunday at 4 AM
Schedule::call(function () {
    try {
        $deleted = app(\App\Services\ContentGuardService::class)->cleanOldFingerprints(30);
        Log::info("[ContentGuard] Cleaned {$deleted} old fingerprints");
    } catch (\Throwable $e) {
        Log::error('Fingerprint cleanup failed: ' . $e->getMessage());
    }
})->weeklyOn(0, '04:00')->name('warmup:fingerprint-cleanup');

// Slot sync: sync orphaned slot statuses every 10 minutes
Schedule::call(function () {
    try {
        $synced = app(\App\Services\SlotSchedulerService::class)->syncSlotStatuses();
        if ($synced > 0) Log::info("[SlotScheduler] Synced {$synced} orphaned slots");
    } catch (\Throwable $e) {
        Log::error('Slot sync failed: ' . $e->getMessage());
    }
})->everyTenMinutes()->name('warmup:slot-sync');

// ── Phase I: Deliverability Intelligence Scheduled Tasks ──

// Reputation scan: daily at 22:00
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
    try {
        $results = app(\App\Services\ReputationService::class)->runFullScan();
        Log::info("[Reputation] Scan: {$results['domains_scored']} domains, {$results['senders_scored']} senders scored");
        $diagnostic->recordHeartbeat('warmup:reputation-scan', true, null, 1440);
    } catch (\Throwable $e) {
        Log::error('Reputation scan failed: ' . $e->getMessage());
        $diagnostic->recordHeartbeat('warmup:reputation-scan', false, $e->getMessage(), 1440);
    }
})->dailyAt('22:00')->name('warmup:reputation-scan');

// Strategy optimizer: daily at 22:30 (after reputation to use fresh scores)
Schedule::call(function () {
    $diagnostic = app(\App\Services\SystemDiagnosticService::class);
    try {
        $results = app(\App\Services\SendingStrategyService::class)->analyzeAll(true);
        Log::info("[Strategy] Analyzed: {$results['analyzed']}, Ramp: {$results['ramp_up']}, Slow: {$results['slow_down']}, Pause: {$results['pause']}");
        $diagnostic->recordHeartbeat('warmup:strategy-optimizer', true, null, 1440);
    } catch (\Throwable $e) {
        Log::error('Strategy optimizer failed: ' . $e->getMessage());
        $diagnostic->recordHeartbeat('warmup:strategy-optimizer', false, $e->getMessage(), 1440);
    }
})->dailyAt('22:30')->name('warmup:strategy-optimizer');

// DNS audit: runs with weekly DNS check (Monday 03:15) to track changes
Schedule::call(function () {
    try {
        $reputationService = app(\App\Services\ReputationService::class);
        $domainService = app(\App\Services\DomainService::class);
        $domains = \App\Models\Domain::all();

        foreach ($domains as $domain) {
            try {
                $previousState = [
                    'spf_status' => $domain->spf_status,
                    'dkim_status' => $domain->dkim_status,
                    'dmarc_status' => $domain->dmarc_status,
                    'mx_status' => $domain->mx_status,
                ];
                $domainService->checkDns($domain);
                $domain->refresh();
                $reputationService->auditDnsChanges($domain, $previousState);
            } catch (\Throwable $e) {
                Log::warning('DNS audit failed for ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }
        Log::info('[DNS Audit] Completed audit for ' . $domains->count() . ' domains');
    } catch (\Throwable $e) {
        Log::error('DNS audit failed: ' . $e->getMessage());
    }
})->weeklyOn(1, '03:15')->name('warmup:dns-audit');
