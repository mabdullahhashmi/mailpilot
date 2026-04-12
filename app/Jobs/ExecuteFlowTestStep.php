<?php

namespace App\Jobs;

use App\Models\FlowTestStep;
use App\Services\FlowTestService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteFlowTestStep implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $stepId)
    {
        $this->queue = 'warmup';
    }

    public function handle(FlowTestService $flowTests): void
    {
        $step = FlowTestStep::with(['run.senderMailbox', 'seedMailbox'])->find($this->stepId);

        if (!$step) {
            return;
        }

        if (in_array($step->status, ['completed', 'failed', 'skipped'], true)) {
            if ($step->flow_test_run_id) {
                $flowTests->refreshRunStatus($step->flow_test_run_id);
            }
            return;
        }

        $run = $step->run;
        if (!$run || in_array($run->status, ['completed', 'failed'], true)) {
            return;
        }

        $previousStep = FlowTestStep::where('flow_test_run_id', $step->flow_test_run_id)
            ->where('seed_mailbox_id', $step->seed_mailbox_id)
            ->where('step_index', '<', $step->step_index)
            ->orderByDesc('step_index')
            ->first();

        if ($previousStep && $previousStep->status !== 'completed') {
            if (in_array($previousStep->status, ['failed', 'skipped'], true)) {
                $step->update([
                    'status' => 'skipped',
                    'executed_at' => now(),
                    'error_message' => 'Skipped because previous step failed.',
                ]);
                $flowTests->refreshRunStatus($step->flow_test_run_id);
                return;
            }

            // Preserve strict order for each sender-seed chain.
            self::dispatch($step->id)->onQueue('warmup')->delay(now()->addSeconds(5));
            return;
        }

        $step->update(['status' => 'executing']);

        try {
            $result = $flowTests->executeStep($step);

            if (!empty($result['deferred'])) {
                $payload = array_merge($step->payload ?? [], $result['payload'] ?? []);
                $retryAfter = max(5, (int) ($result['retry_after_seconds'] ?? 20));

                $step->update([
                    'status' => 'pending',
                    'notes' => $result['notes'] ?? $step->notes,
                    'payload' => $payload,
                    'error_message' => null,
                    'executed_at' => null,
                ]);

                self::dispatch($step->id)->onQueue('warmup')->delay(now()->addSeconds($retryAfter));
                return;
            }

            $step->update([
                'status' => 'completed',
                'executed_at' => now(),
                'notes' => $result['notes'] ?? null,
                'message_id' => $result['message_id'] ?? null,
                'in_reply_to' => $result['in_reply_to'] ?? null,
                'error_message' => null,
            ]);
        } catch (\Throwable $e) {
            Log::warning("Flow test step #{$step->id} failed: {$e->getMessage()}");

            $step->update([
                'status' => 'failed',
                'executed_at' => now(),
                'error_message' => substr($e->getMessage(), 0, 500),
            ]);
        }

        $flowTests->refreshRunStatus($step->flow_test_run_id);
    }
}
