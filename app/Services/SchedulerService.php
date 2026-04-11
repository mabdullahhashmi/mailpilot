<?php

namespace App\Services;

use App\Models\WarmupEvent;
use App\Models\SchedulerRun;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class SchedulerService
{
    public function __construct(
        private EventExecutionService $executor,
    ) {}

    /**
     * Main scheduler loop. Called every 1-5 minutes via cron.
     * Fetches due events, locks them, executes them idempotently.
     */
    public function processEvents(int $batchSize = 20, int $maxBatches = 5): SchedulerRun
    {
        $run = null;
        $startedAt = now();

        try {
            $run = SchedulerRun::create([
                'started_at' => $startedAt,
                'events_processed' => 0,
                'events_succeeded' => 0,
                'events_failed' => 0,
                'events_skipped' => 0,
            ]);
        } catch (\Throwable $e) {
            // Never block event execution if scheduler run logging table is unavailable.
            Log::warning('SchedulerRun create failed, continuing without persistence: ' . $e->getMessage());
        }

        $startTime = microtime(true);

        // Release stale locks (older than 5 minutes)
        $releasedLocks = $this->releaseStaleEventLocks();

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;
        $dueFound = 0;
        $batchesRun = 0;

        $batchSize = max(1, $batchSize);
        $maxBatches = max(1, $maxBatches);

        while ($batchesRun < $maxBatches) {
            $batchesRun++;

            // Fetch due events ordered by priority and scheduled time
            $dueEvents = WarmupEvent::where('status', 'pending')
                ->where('scheduled_at', '<=', now())
                ->orderBy('priority', 'asc')
                ->orderBy('scheduled_at', 'asc')
                ->limit($batchSize)
                ->get();

            if ($dueEvents->isEmpty()) {
                break;
            }

            $dueFound += $dueEvents->count();

            foreach ($dueEvents as $event) {
                $lockToken = Str::uuid()->toString();

                // Atomic lock acquisition
                if (!$event->acquireLock($lockToken)) {
                    $skipped++;
                    continue;
                }

                $processed++;

                try {
                    // Execute synchronously — no persistent queue worker needed on shared hosting
                    $event->update(['status' => 'executing']);
                    $this->executor->execute($event);
                    $succeeded++;
                } catch (\Throwable $e) {
                    Log::error("Scheduler: Event #{$event->id} ({$event->event_type}) failed: " . get_class($e) . ': ' . $e->getMessage(), [
                        'event_id' => $event->id,
                        'event_type' => $event->event_type,
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ]);

                    $event->update([
                        'status' => 'failed',
                        'retry_count' => $event->retry_count + 1,
                        'failure_reason' => substr(get_class($e) . ': ' . $e->getMessage(), 0, 500),
                        'lock_token' => null,
                        'lock_expires_at' => null,
                    ]);

                    if ($event->isRetryable()) {
                        // Schedule retry with exponential backoff
                        $backoffMinutes = pow(2, $event->retry_count) * 2;
                        $event->update([
                            'status' => 'pending',
                            'scheduled_at' => now()->addMinutes($backoffMinutes),
                        ]);
                    } else {
                        $event->update(['status' => 'final_failed']);
                        // Notify safety service about repeated failure
                        app(SafetyService::class)->handleEventFinalFailure($event);
                    }

                    $slot = \App\Models\SendSlot::where('warmup_event_id', $event->id)->first();
                    if ($slot) {
                        app(SlotSchedulerService::class)->markSlotFailed($slot);
                    }

                    $failed++;
                }
            }

            if ($dueEvents->count() < $batchSize) {
                break;
            }
        }

        $executionTime = (int)((microtime(true) - $startTime) * 1000);
        $remainingDue = WarmupEvent::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->count();

        $payload = [
            'finished_at' => now(),
            'events_processed' => $processed,
            'events_succeeded' => $succeeded,
            'events_failed' => $failed,
            'events_skipped' => $skipped,
            'execution_time_ms' => $executionTime,
            'summary' => [
                'batch_size' => $batchSize,
                'batches_run' => $batchesRun,
                'max_batches' => $maxBatches,
                'due_found' => $dueFound,
                'remaining_due' => $remainingDue,
                'released_stale_locks' => $releasedLocks,
            ],
        ];

        if ($run) {
            $run->update($payload);
            return $run->fresh();
        }

        // Return an in-memory run object when persistence is unavailable.
        return new SchedulerRun(array_merge([
            'started_at' => $startedAt,
        ], $payload));
    }

    private function releaseStaleEventLocks(): int
    {
        $releasedLockedOrExecuting = WarmupEvent::whereIn('status', ['locked', 'executing'])
            ->where('lock_expires_at', '<', now())
            ->update([
                'status' => 'pending',
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);

        $releasedPendingWithStaleToken = WarmupEvent::where('status', 'pending')
            ->whereNotNull('lock_token')
            ->where(function ($q) {
                $q->whereNull('lock_expires_at')
                  ->orWhere('lock_expires_at', '<', now());
            })
            ->update([
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);

        return $releasedLockedOrExecuting + $releasedPendingWithStaleToken;
    }
}
