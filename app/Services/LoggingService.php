<?php

namespace App\Services;

use App\Models\WarmupEventLog;
use Illuminate\Support\Facades\Log;

class LoggingService
{
    /**
     * Log a warmup event execution.
     */
    public function logEvent(
        int $eventId,
        ?int $threadId,
        ?int $campaignId,
        string $eventType,
        string $outcome,
        ?string $details = null,
        ?int $executionTimeMs = null
    ): WarmupEventLog {
        return WarmupEventLog::create([
            'warmup_event_id' => $eventId,
            'thread_id' => $threadId,
            'warmup_campaign_id' => $campaignId,
            'event_type' => $eventType,
            'outcome' => $outcome,
            'details' => $details,
            'execution_time_ms' => $executionTimeMs,
        ]);
    }

    /**
     * Log a system-level message.
     */
    public function logSystem(string $level, string $message, array $context = []): void
    {
        $context['source'] = 'warmup_engine';
        Log::channel('daily')->log($level, "[WarmupEngine] {$message}", $context);
    }

    /**
     * Log a safety action (pause, cap hit, etc.).
     */
    public function logSafety(string $action, string $message, array $context = []): void
    {
        $context['safety_action'] = $action;
        Log::channel('daily')->warning("[WarmupSafety] {$message}", $context);
    }

    /**
     * Get recent event logs for display.
     */
    public function getRecentLogs(int $limit = 50, ?int $campaignId = null): \Illuminate\Support\Collection
    {
        $query = WarmupEventLog::with(['warmupEvent'])
            ->orderBy('created_at', 'desc');

        if ($campaignId) {
            $query->where('warmup_campaign_id', $campaignId);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Get execution time stats for monitoring.
     */
    public function getPerformanceStats(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        $logs = WarmupEventLog::where('created_at', '>=', $since)
            ->whereNotNull('execution_time_ms')
            ->get();

        if ($logs->isEmpty()) {
            return [
                'avg_ms' => 0,
                'max_ms' => 0,
                'min_ms' => 0,
                'total' => 0,
                'success_rate' => 0,
            ];
        }

        $successCount = $logs->where('outcome', 'success')->count();

        return [
            'avg_ms' => round($logs->avg('execution_time_ms')),
            'max_ms' => $logs->max('execution_time_ms'),
            'min_ms' => $logs->min('execution_time_ms'),
            'total' => $logs->count(),
            'success_rate' => round(($successCount / $logs->count()) * 100, 1),
        ];
    }
}
