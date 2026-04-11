<?php

namespace App\Services;

use App\Models\CronHeartbeat;
use App\Models\DiagnosticSnapshot;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\WarmupEvent;
use App\Models\WarmupEventLog;
use App\Models\SystemAlert;
use App\Models\MailboxHealthLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SystemDiagnosticService
{
    /**
     * Record a cron heartbeat when a task runs.
     */
    public function recordHeartbeat(string $taskName, bool $success, ?string $error = null, int $expectedIntervalMinutes = 60): void
    {
        $heartbeat = CronHeartbeat::firstOrCreate(
            ['task_name' => $taskName],
            ['expected_interval_minutes' => $expectedIntervalMinutes]
        );

        $history = $heartbeat->run_history ?? [];
        $history[] = [
            'at' => now()->toDateTimeString(),
            'ok' => $success,
            'error' => $error,
        ];
        // Keep last 50 entries
        $history = array_slice($history, -50);

        $heartbeat->update([
            'last_run_at' => now(),
            'last_success_at' => $success ? now() : $heartbeat->last_success_at,
            'status' => $success ? 'healthy' : 'failed',
            'consecutive_failures' => $success ? 0 : $heartbeat->consecutive_failures + 1,
            'last_error' => $error,
            'run_history' => $history,
        ]);
    }

    /**
     * Check all cron heartbeats and update their status.
     */
    public function checkCronHealth(): array
    {
        $heartbeats = CronHeartbeat::all();
        $results = [];

        foreach ($heartbeats as $hb) {
            $oldStatus = $hb->status;

            if ($hb->isMissed()) {
                $hb->update(['status' => 'missed']);
            } elseif ($hb->isLate()) {
                $hb->update(['status' => 'late']);
            } elseif ($hb->consecutive_failures === 0) {
                $hb->update(['status' => 'healthy']);
            }

            // Alert on status change to missed/failed
            if ($oldStatus !== $hb->status && in_array($hb->status, ['missed', 'failed'])) {
                SystemAlert::create([
                    'title' => "Cron task {$hb->status}: {$hb->task_name}",
                    'message' => $hb->status === 'missed'
                        ? "Task '{$hb->task_name}' hasn't run in " . ($hb->last_run_at ? $hb->last_run_at->diffForHumans() : 'never')
                        : "Task '{$hb->task_name}' failed: {$hb->last_error}",
                    'severity' => $hb->status === 'missed' ? 'critical' : 'warning',
                    'context_type' => 'cron_health',
                ]);
            }

            $results[] = [
                'task' => $hb->task_name,
                'status' => $hb->status,
                'last_run' => $hb->last_run_at?->diffForHumans(),
                'last_success' => $hb->last_success_at?->diffForHumans(),
                'failures' => $hb->consecutive_failures,
            ];
        }

        return $results;
    }

    /**
     * Create a daily diagnostic snapshot — full system self-check.
     */
    public function createDailySnapshot(): DiagnosticSnapshot
    {
        $totalSenders = SenderMailbox::count();
        $activeSenders = SenderMailbox::where('status', 'active')->count();
        $pausedSenders = SenderMailbox::where('status', 'paused')->count();

        $totalSeeds = SeedMailbox::count();
        $activeSeeds = SeedMailbox::where('status', 'active')->count();
        $disabledSeeds = SeedMailbox::whereIn('status', ['disabled', 'paused'])->count();

        $eventsPlanned = WarmupEvent::whereDate('scheduled_at', today())->count();
        $eventsCompleted = WarmupEvent::whereDate('executed_at', today())->where('status', 'completed')->count();
        $eventsFailed = WarmupEvent::whereDate('scheduled_at', today())->where('status', 'final_failed')->count();

                // Stuck events: stale locks or pending long past schedule
        $eventsStuck = WarmupEvent::where(function ($q) {
                        $q->whereIn('status', ['locked', 'executing'])
                            ->where('lock_expires_at', '<', now());
                })->orWhere(function ($q) {
                        $q->where('status', 'pending')
                            ->whereNotNull('lock_token')
                            ->where(function ($qq) {
                                    $qq->whereNull('lock_expires_at')
                                         ->orWhere('lock_expires_at', '<', now());
                            });
        })->orWhere(function ($q) {
            $q->where('status', 'pending')
              ->where('scheduled_at', '<', now()->subMinutes(30));
        })->count();

        // Average queue lag: how late pending events are
        $avgLag = WarmupEvent::where('status', 'pending')
            ->where('scheduled_at', '<', now())
            ->selectRaw('AVG(TIMESTAMPDIFF(SECOND, scheduled_at, NOW())) as avg_lag')
            ->value('avg_lag') ?? 0;

        // SMTP/IMAP failures today
        $smtpFailures = WarmupEventLog::where('outcome', 'failure')
            ->where('details', 'like', '%SMTP%')
            ->whereDate('created_at', today())
            ->count();

        $imapFailures = WarmupEventLog::where('outcome', 'failure')
            ->where('details', 'like', '%IMAP%')
            ->whereDate('created_at', today())
            ->count();

        // Health scores
        $avgHealth = MailboxHealthLog::where('log_date', today())->avg('health_score') ?? 0;

        $avgBounce = 0;
        $healthLogs = MailboxHealthLog::where('log_date', today())->where('sends_today', '>', 0)->get();
        if ($healthLogs->isNotEmpty()) {
            $avgBounce = $healthLogs->avg(fn ($l) => ($l->bounces_today / $l->sends_today) * 100);
        }

        // Cron statuses
        $cronStatuses = CronHeartbeat::all()->mapWithKeys(fn ($h) => [$h->task_name => $h->status])->toArray();

        // Alert summary
        $alertsSummary = [
            'critical' => SystemAlert::where('severity', 'critical')->where('is_read', false)->count(),
            'warning' => SystemAlert::where('severity', 'warning')->where('is_read', false)->count(),
            'info' => SystemAlert::where('severity', 'info')->where('is_read', false)->count(),
        ];

        // Determine overall status
        $overall = 'healthy';
        if ($eventsStuck > 5 || $avgBounce > 5 || $pausedSenders > $activeSenders) {
            $overall = 'critical';
        } elseif ($eventsStuck > 0 || $avgBounce > 2 || $smtpFailures > 3 || $imapFailures > 5) {
            $overall = 'degraded';
        }

        return DiagnosticSnapshot::updateOrCreate(
            ['snapshot_date' => today()],
            [
                'total_senders' => $totalSenders,
                'active_senders' => $activeSenders,
                'paused_senders' => $pausedSenders,
                'total_seeds' => $totalSeeds,
                'active_seeds' => $activeSeeds,
                'disabled_seeds' => $disabledSeeds,
                'events_planned' => $eventsPlanned,
                'events_completed' => $eventsCompleted,
                'events_failed' => $eventsFailed,
                'events_stuck' => $eventsStuck,
                'avg_queue_lag_seconds' => (int)$avgLag,
                'smtp_failures' => $smtpFailures,
                'imap_failures' => $imapFailures,
                'avg_health_score' => round($avgHealth, 2),
                'avg_bounce_rate' => round($avgBounce, 2),
                'cron_statuses' => $cronStatuses,
                'alerts_summary' => $alertsSummary,
                'overall_status' => $overall,
            ]
        );
    }

    /**
     * Get the latest diagnostic snapshot.
     */
    public function getLatestSnapshot(): ?DiagnosticSnapshot
    {
        return DiagnosticSnapshot::latest('snapshot_date')->first();
    }

    /**
     * Run a live system diagnostic (not persisted).
     */
    public function runLiveDiagnostic(): array
    {
        $snapshot = $this->createDailySnapshot();
        $cronHealth = $this->checkCronHealth();

        // Get stuck events detail
        $stuckEvents = WarmupEvent::where(function ($q) {
                        $q->whereIn('status', ['locked', 'executing'])
                            ->where('lock_expires_at', '<', now());
                })->orWhere(function ($q) {
                        $q->where('status', 'pending')
                            ->whereNotNull('lock_token')
                            ->where(function ($qq) {
                                    $qq->whereNull('lock_expires_at')
                                         ->orWhere('lock_expires_at', '<', now());
                            });
        })->orWhere(function ($q) {
            $q->where('status', 'pending')
              ->where('scheduled_at', '<', now()->subMinutes(30));
        })->limit(20)->get()->map(fn ($e) => [
            'id' => $e->id,
            'type' => $e->event_type,
            'status' => $e->status,
            'scheduled_at' => $e->scheduled_at->toDateTimeString(),
            'lag_minutes' => $e->scheduled_at->diffInMinutes(now()),
        ])->toArray();

        return [
            'snapshot' => $snapshot->toArray(),
            'cron_health' => $cronHealth,
            'stuck_events' => $stuckEvents,
            'generated_at' => now()->toDateTimeString(),
        ];
    }

    /**
     * Attempt to auto-fix stuck events by releasing expired locks.
     */
    public function fixStuckEvents(): int
    {
        $fixedLockedOrExecuting = WarmupEvent::whereIn('status', ['locked', 'executing'])
            ->where('lock_expires_at', '<', now())
            ->update([
                'status' => 'pending',
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);

        $fixedPendingWithStaleToken = WarmupEvent::where('status', 'pending')
            ->whereNotNull('lock_token')
            ->where(function ($q) {
                $q->whereNull('lock_expires_at')
                  ->orWhere('lock_expires_at', '<', now());
            })
            ->update([
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);

        $fixed = $fixedLockedOrExecuting + $fixedPendingWithStaleToken;

        if ($fixed > 0) {
            Log::info("[Diagnostic] Released {$fixed} stuck event locks");
        }

        return $fixed;
    }
}
