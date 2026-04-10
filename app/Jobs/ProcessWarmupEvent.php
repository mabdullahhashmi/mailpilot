<?php

namespace App\Jobs;

use App\Models\WarmupEvent;
use App\Models\SystemAlert;
use App\Services\EventExecutionService;
use App\Services\SafetyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessWarmupEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 120;
    public int $timeout = 120;

    public function __construct(
        public int $eventId,
        public string $lockToken,
    ) {
        $this->queue = 'warmup';
    }

    public function handle(EventExecutionService $executor, SafetyService $safety): void
    {
        $event = WarmupEvent::find($this->eventId);

        if (!$event) {
            Log::warning("ProcessWarmupEvent: Event #{$this->eventId} not found (deleted?)");
            return;
        }

        // Verify we still own the lock
        if ($event->lock_token !== $this->lockToken) {
            Log::info("ProcessWarmupEvent: Lock lost for event #{$this->eventId}");
            return;
        }

        try {
            $event->update(['status' => 'executing']);
            $executor->execute($event);
        } catch (\Throwable $e) {
            Log::error("ProcessWarmupEvent: Event #{$this->eventId} failed: {$e->getMessage()}");

            $event->update([
                'retry_count' => $event->retry_count + 1,
                'failure_reason' => $e->getMessage(),
                'lock_token' => null,
                'lock_expires_at' => null,
            ]);

            if ($event->isRetryable()) {
                $backoffMinutes = pow(2, $event->retry_count) * 2;
                $event->update([
                    'status' => 'pending',
                    'scheduled_at' => now()->addMinutes($backoffMinutes),
                ]);
            } else {
                $event->update(['status' => 'final_failed']);
                $safety->handleEventFinalFailure($event);

                SystemAlert::fire(
                    'warning',
                    'Event Permanently Failed',
                    "Event #{$event->id} ({$event->event_type}) failed after all retries: {$e->getMessage()}",
                    'campaign',
                    $event->warmup_campaign_id
                );
            }

            throw $e; // Let the queue handle retry
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error("ProcessWarmupEvent: Job for event #{$this->eventId} permanently failed: {$exception->getMessage()}");
    }
}
