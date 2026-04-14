<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FlowTestStep;
use App\Models\SenderMailbox;
use App\Models\MailboxHealthLog;
use App\Services\HealthService;
use App\Services\ReportingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class SenderHealthController extends Controller
{
    public function __construct(
        private HealthService $healthService,
        private ReportingService $reportingService,
    ) {}

    /**
     * Get health overview for all senders.
     */
    public function index(): JsonResponse
    {
        $senders = SenderMailbox::with(['domain'])->get();

        $data = $senders->map(function ($sender) {
            $this->healthService->syncHealthLogsFromMessages($sender, 60);
            $this->healthService->syncHealthLogsFromFlowTests($sender, 60);
            $this->healthService->syncHealthLogsFromImap($sender, 60);

            $report = $this->reportingService->senderReport($sender);
            $stats = $this->resolveMergedStats($sender, $report, 30);

            $recentLogs = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
                ->orderBy('log_date', 'desc')
                ->orderBy('id', 'desc')
                ->take(2)
                ->get();

            $latestLog = $recentLogs->first();
            $previousLog = $recentLogs->get(1);

            $latestBreakdown = $latestLog
                ? $this->healthService->calculateSenderHealthBreakdown($sender, $latestLog)
                : null;
            $healthScore = (int) ($latestBreakdown['score'] ?? 0);

            $previousScore = $previousLog
                ? (int) (($this->healthService->calculateSenderHealthBreakdown($sender, $previousLog)['score'] ?? 0))
                : null;

            $hasActivity = ((int) ($stats['total_sent'] ?? 0) > 0)
                || ((int) ($stats['total_replied'] ?? 0) > 0)
                || ((int) ($stats['total_bounced'] ?? 0) > 0);

            if (!$hasActivity) {
                $healthScore = 0;
                $previousScore = $previousScore ?? 0;
            } elseif (!$latestLog || $healthScore <= 0) {
                $healthScore = $this->estimateHealthScore(
                    (int) ($stats['total_sent'] ?? 0),
                    (int) ($stats['total_replied'] ?? 0),
                    (int) ($stats['total_bounced'] ?? 0)
                );
            }

            $trend = $this->buildTrend($healthScore, $previousScore);

            return [
                'id' => $sender->id,
                'email' => $sender->email_address,
                'provider_type' => $sender->provider_type,
                'status' => $sender->status,
                'domain' => $sender->domain?->domain_name,
                'health_score' => $healthScore,
                'trend' => $trend,
                'last_log_date' => $latestLog?->log_date?->format('Y-m-d'),
                'stats' => $stats,
                // Legacy keys kept to avoid breaking any existing clients.
                'avg_health_30d' => (float) ($stats['avg_health'] ?? 0),
                'total_sends_30d' => (int) ($stats['total_sent'] ?? 0),
                'total_replies_30d' => (int) ($stats['total_replied'] ?? 0),
                'total_bounces_30d' => (int) ($stats['total_bounced'] ?? 0),
                'reply_rate' => (float) ($stats['reply_rate'] ?? 0),
                'bounce_rate' => (float) ($stats['bounce_rate'] ?? 0),
            ];
        });

        return response()
            ->json($data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    /**
     * Get detailed health history for a single sender.
     */
    public function show(int $id): JsonResponse
    {
        $sender = SenderMailbox::with('domain')->findOrFail($id);
        $threadSyncTouched = $this->healthService->syncHealthLogsFromMessages($sender, 60);
        $flowSyncTouched = $this->healthService->syncHealthLogsFromFlowTests($sender, 60);
        $imapSyncTouched = $this->healthService->syncHealthLogsFromImap($sender, 60);

        $report = $this->reportingService->senderReport($sender);
        $stats = $this->resolveMergedStats($sender, $report, 30);

        $healthLogsCollection = MailboxHealthLog::where('sender_mailbox_id', $id)
            ->orderBy('log_date', 'desc')
            ->orderBy('id', 'desc')
            ->take(60)
            ->get();

        $latestLog = $healthLogsCollection->first();
        $previousLog = $healthLogsCollection->get(1);

        $latestBreakdown = $latestLog
            ? $this->healthService->calculateSenderHealthBreakdown($sender, $latestLog)
            : $this->healthService->calculateSenderHealthBreakdown($sender);

        $healthScore = (int) ($latestBreakdown['score'] ?? 0);
        $previousScore = $previousLog
            ? (int) (($this->healthService->calculateSenderHealthBreakdown($sender, $previousLog)['score'] ?? 0))
            : null;

        $hasActivity = ((int) ($stats['total_sent'] ?? 0) > 0)
            || ((int) ($stats['total_replied'] ?? 0) > 0)
            || ((int) ($stats['total_bounced'] ?? 0) > 0);

        if (!$hasActivity) {
            $healthScore = 0;
            $previousScore = $previousScore ?? 0;
        } elseif (!$latestLog || $healthScore <= 0) {
            $healthScore = $this->estimateHealthScore(
                (int) ($stats['total_sent'] ?? 0),
                (int) ($stats['total_replied'] ?? 0),
                (int) ($stats['total_bounced'] ?? 0)
            );
        }

        $trend = $this->buildTrend($healthScore, $previousScore);
        $healthLogs = $this->mapHealthHistory($sender, $healthLogsCollection);

        return response()->json([
            'id' => $sender->id,
            'email' => $sender->email_address,
            'status' => $sender->status,
            'provider_type' => $sender->provider_type,
            'domain' => $sender->domain?->domain_name,
            'health_score' => $healthScore,
            'stats' => $stats,
            'health_overview' => [
                'current_score' => $healthScore,
                'previous_score' => $previousScore,
                'score_change' => $trend['delta'],
                'trend' => $trend['direction'],
                'last_log_date' => $latestLog?->log_date?->format('Y-m-d'),
                'tracking_scope' => 'Metrics include MailPilot tracked threads plus IMAP mailbox activity sync for this sender.',
                'sync' => [
                    'thread_days_touched' => (int) $threadSyncTouched,
                    'flow_test_days_touched' => (int) $flowSyncTouched,
                    'imap_days_touched' => (int) $imapSyncTouched,
                ],
                'metrics' => $latestBreakdown['metrics'] ?? [],
                'increasing_points' => $latestBreakdown['increasing_points'] ?? [],
                'decreasing_points' => $latestBreakdown['decreasing_points'] ?? [],
                'score_breakdown' => $latestBreakdown['score_breakdown'] ?? [],
            ],
            'health_history' => $healthLogs,
            // Legacy keys kept to avoid breaking any existing clients.
            'sender' => [
                'id' => $sender->id,
                'email' => $sender->email_address,
                'status' => $sender->status,
                'provider' => $sender->provider_type,
                'health_score' => $healthScore,
                'domain' => $sender->domain?->domain_name,
            ],
            'report' => $report,
            'history' => $healthLogs,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    private function mapHealthHistory(SenderMailbox $sender, Collection $logs): array
    {
        $logs = $logs->values();

        return $logs->map(function ($log, $index) use ($logs, $sender) {
            $olderLog = $logs->get($index + 1);

            $breakdown = $this->healthService->calculateSenderHealthBreakdown($sender, $log);
            $score = (int) ($breakdown['score'] ?? 0);

            $rowHasActivity = ((int) ($log->sends_today ?? 0) > 0)
                || ((int) ($log->replies_today ?? 0) > 0)
                || ((int) ($log->bounces_today ?? 0) > 0)
                || ((int) ($log->failed_events ?? 0) > 0);

            if (!$rowHasActivity) {
                $score = 0;
            }

            $olderScore = null;
            if ($olderLog) {
                $olderBreakdown = $this->healthService->calculateSenderHealthBreakdown($sender, $olderLog);
                $olderScore = (int) ($olderBreakdown['score'] ?? 0);

                $olderHasActivity = ((int) ($olderLog->sends_today ?? 0) > 0)
                    || ((int) ($olderLog->replies_today ?? 0) > 0)
                    || ((int) ($olderLog->bounces_today ?? 0) > 0)
                    || ((int) ($olderLog->failed_events ?? 0) > 0);

                if (!$olderHasActivity) {
                    $olderScore = 0;
                }
            }

            $scoreDelta = $olderScore === null ? 0 : $score - $olderScore;

            return [
                'id' => $log->id,
                'log_date' => $log->log_date?->format('Y-m-d'),
                'warmup_day' => (int) ($log->warmup_day ?? 0),
                'sent_today' => (int) ($log->sends_today ?? 0),
                'replied_today' => (int) ($log->replies_today ?? 0),
                'failed_events' => (int) ($log->failed_events ?? 0),
                'bounces_today' => (int) ($log->bounces_today ?? 0),
                'health_score' => $score,
                'score_delta' => $scoreDelta,
                'trend' => $scoreDelta > 0 ? 'up' : ($scoreDelta < 0 ? 'down' : 'flat'),
            ];
        })->toArray();
    }

    private function buildTrend(int $currentScore, ?int $previousScore): array
    {
        if ($previousScore === null) {
            return [
                'direction' => 'flat',
                'delta' => 0,
                'previous_score' => null,
            ];
        }

        $delta = $currentScore - $previousScore;

        return [
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
            'delta' => $delta,
            'previous_score' => $previousScore,
        ];
    }

    private function resolveMergedStats(SenderMailbox $sender, array $report, int $days = 30): array
    {
        $flow = $this->flowTestStats($sender, $days);

        $totalSent = max((int) ($report['total_sends_30d'] ?? 0), (int) ($flow['total_sent'] ?? 0));
        $totalReplied = max((int) ($report['total_replies_30d'] ?? 0), (int) ($flow['total_replied'] ?? 0));
        $totalBounced = max((int) ($report['total_bounces_30d'] ?? 0), (int) ($flow['total_bounced'] ?? 0));

        return [
            'avg_health' => (float) ($report['avg_health_30d'] ?? 0),
            'total_sent' => $totalSent,
            'total_replied' => $totalReplied,
            'total_bounced' => $totalBounced,
            'reply_rate' => $totalSent > 0 ? round(($totalReplied / $totalSent) * 100, 1) : 0,
            'bounce_rate' => $totalSent > 0 ? round(($totalBounced / $totalSent) * 100, 1) : 0,
            'sources' => [
                'mailbox_logs' => [
                    'total_sent' => (int) ($report['total_sends_30d'] ?? 0),
                    'total_replied' => (int) ($report['total_replies_30d'] ?? 0),
                    'total_bounced' => (int) ($report['total_bounces_30d'] ?? 0),
                ],
                'flow_tests' => $flow,
            ],
        ];
    }

    private function flowTestStats(SenderMailbox $sender, int $days = 30): array
    {
        $from = now()->subDays(max(1, $days))->startOfDay();

        $row = FlowTestStep::query()
            ->join('flow_test_runs', 'flow_test_steps.flow_test_run_id', '=', 'flow_test_runs.id')
            ->where('flow_test_runs.sender_mailbox_id', $sender->id)
            ->whereNotNull('flow_test_steps.executed_at')
            ->where('flow_test_steps.executed_at', '>=', $from)
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'completed' AND flow_test_steps.action_type IN ('sender_send_initial','sender_reply') THEN 1 ELSE 0 END) as sends")
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'completed' AND flow_test_steps.action_type = 'seed_reply' THEN 1 ELSE 0 END) as replies")
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'failed' AND flow_test_steps.action_type IN ('sender_send_initial','sender_reply') THEN 1 ELSE 0 END) as bounces")
            ->first();

        return [
            'total_sent' => (int) ($row->sends ?? 0),
            'total_replied' => (int) ($row->replies ?? 0),
            'total_bounced' => (int) ($row->bounces ?? 0),
        ];
    }

    private function estimateHealthScore(int $sends, int $replies, int $bounces): int
    {
        if ($sends <= 0 && $replies <= 0 && $bounces <= 0) {
            return 0;
        }

        $score = 50;

        if ($sends > 0) {
            $score += 10;
            $replyRate = $replies / max(1, $sends);
            $bounceRate = $bounces / max(1, $sends);

            $score += min(20, (int) round($replyRate * 50));
            $score -= min(30, (int) round($bounceRate * 100));
        }

        return max(0, min(100, $score));
    }
}
