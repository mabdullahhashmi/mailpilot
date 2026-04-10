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
    public function processEvents(int $batchSize = 20): SchedulerRun
    {
        $run = SchedulerRun::create([
            'started_at' => now(),
            'events_processed' => 0,
            'events_succeeded' => 0,
            'events_failed' => 0,
            'events_skipped' => 0,
        ]);

        $startTime = microtime(true);

        // Release stale locks (older than 5 minutes)
        $this->releaseStaleEventLocks();

        // Fetch due events ordered by priority and scheduled time
        $dueEvents = WarmupEvent::where('status', 'pending')
            ->where('scheduled_at', '<=', now())
            ->orderBy('priority', 'asc')
            ->orderBy('scheduled_at', 'asc')
            ->limit($batchSize)
            ->get();

        $processed = 0;
        $succeeded = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($dueEvents as $event) {
            $lockToken = Str::uuid()->toString();

            // Atomic lock acquisition
            if (!$event->acquireLock($lockToken)) {
                $skipped++;
                continue;
            }

            $processed++;

            try {
                $event->update(['status' => 'executing']);
                $this->executor->execute($event);
                $succeeded++;
            } catch (\Throwable $e) {
                Log::error("Scheduler: Event #{$event->id} failed: {$e->getMessage()}");

                $event->update([
                    'retry_count' => $event->retry_count + 1,
                    'failure_reason' => $e->getMessage(),
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

                $failed++;
            }
        }

        $executionTime = (int)((microtime(true) - $startTime) * 1000);

        $run->update([
            'finished_at' => now(),
            'events_processed' => $processed,
            'events_succeeded' => $succeeded,
            'events_failed' => $failed,
            'events_skipped' => $skipped,
            'execution_time_ms' => $executionTime,
            'summary' => [
                'batch_size' => $batchSize,
                'due_found' => $dueEvents->count(),
            ],
        ]);

        return $run;
    }

    private function releaseStaleEventLocks(): void
    {
        WarmupEvent::where('status', 'locked')
            ->where('lock_expires_at', '<', now())
            ->update([
                'status' => 'pending',
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);
    }
}
