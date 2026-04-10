<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\SendingStrategyLog;
use App\Models\MailboxHealthLog;
use App\Models\BounceEvent;
use App\Models\PlacementTest;
use App\Models\ReputationScore;
use App\Models\WarmupCampaign;
use Illuminate\Support\Facades\Log;

class SendingStrategyService
{
    /**
     * Analyze a sender and generate an adaptive strategy recommendation.
     */
    public function analyze(SenderMailbox $sender): SendingStrategyLog
    {
        $metrics = $this->gatherMetrics($sender);
        $recommendation = $this->determineRecommendation($metrics);
        $recommendedCap = $this->calculateRecommendedCap($sender, $metrics, $recommendation);

        $campaign = $sender->warmupCampaign;

        $log = SendingStrategyLog::create([
            'sender_mailbox_id' => $sender->id,
            'warmup_campaign_id' => $campaign?->id,
            'recommendation' => $recommendation,
            'current_daily_cap' => $sender->daily_send_cap,
            'recommended_daily_cap' => $recommendedCap,
            'reasoning' => $this->buildReasoning($metrics, $recommendation),
            'metrics_snapshot' => $metrics,
            'was_applied' => false,
        ]);

        return $log;
    }

    /**
     * Analyze and auto-apply strategy for a sender.
     */
    public function analyzeAndApply(SenderMailbox $sender): SendingStrategyLog
    {
        $log = $this->analyze($sender);

        if ($log->recommendation !== 'maintain' && $log->recommended_daily_cap !== $sender->daily_send_cap) {
            $sender->update(['daily_send_cap' => $log->recommended_daily_cap]);
            $log->update(['was_applied' => true]);
            Log::info("[Strategy] Applied {$log->recommendation} for sender #{$sender->id}: cap {$sender->daily_send_cap} -> {$log->recommended_daily_cap}");
        }

        return $log;
    }

    /**
     * Run strategy analysis for all active senders.
     */
    public function analyzeAll(bool $autoApply = false): array
    {
        $senders = SenderMailbox::where('status', 'active')
            ->where('is_warmup_enabled', true)
            ->get();

        $results = ['analyzed' => 0, 'ramp_up' => 0, 'slow_down' => 0, 'maintain' => 0, 'pause' => 0];

        foreach ($senders as $sender) {
            try {
                $log = $autoApply
                    ? $this->analyzeAndApply($sender)
                    : $this->analyze($sender);

                $results['analyzed']++;
                $results[$log->recommendation] = ($results[$log->recommendation] ?? 0) + 1;
            } catch (\Throwable $e) {
                Log::warning("[Strategy] Failed to analyze sender #{$sender->id}: {$e->getMessage()}");
            }
        }

        return $results;
    }

    /**
     * Get strategy history for a sender.
     */
    public function getSenderHistory(SenderMailbox $sender, int $limit = 30): array
    {
        return SendingStrategyLog::where('sender_mailbox_id', $sender->id)
            ->orderByDesc('created_at')
            ->take($limit)
            ->get()
            ->map(fn ($log) => [
                'date' => $log->created_at->format('Y-m-d H:i'),
                'recommendation' => $log->recommendation,
                'current_cap' => $log->current_daily_cap,
                'recommended_cap' => $log->recommended_daily_cap,
                'reasoning' => $log->reasoning,
                'applied' => $log->was_applied,
            ])
            ->toArray();
    }

    /**
     * Get strategy overview dashboard data.
     */
    public function getDashboardData(): array
    {
        $today = SendingStrategyLog::whereDate('created_at', today())->get();

        $senders = SenderMailbox::where('status', 'active')
            ->where('is_warmup_enabled', true)
            ->get(['id', 'email_address', 'daily_send_cap', 'current_warmup_day', 'reputation_score', 'placement_score']);

        return [
            'todays_recommendations' => [
                'total' => $today->count(),
                'ramp_up' => $today->where('recommendation', 'ramp_up')->count(),
                'slow_down' => $today->where('recommendation', 'slow_down')->count(),
                'maintain' => $today->where('recommendation', 'maintain')->count(),
                'pause' => $today->where('recommendation', 'pause')->count(),
            ],
            'sender_caps' => $senders->map(fn ($s) => [
                'id' => $s->id,
                'email' => $s->email_address,
                'daily_cap' => $s->daily_send_cap,
                'warmup_day' => $s->current_warmup_day,
                'reputation' => $s->reputation_score,
                'placement' => $s->placement_score,
            ])->values()->toArray(),
            'recent_changes' => SendingStrategyLog::where('was_applied', true)
                ->orderByDesc('created_at')
                ->take(10)
                ->get()
                ->map(fn ($l) => [
                    'sender_id' => $l->sender_mailbox_id,
                    'recommendation' => $l->recommendation,
                    'old_cap' => $l->current_daily_cap,
                    'new_cap' => $l->recommended_daily_cap,
                    'date' => $l->created_at->format('Y-m-d H:i'),
                ])
                ->toArray(),
        ];
    }

    // ── Private Methods ──

    private function gatherMetrics(SenderMailbox $sender): array
    {
        $healthLogs7d = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', '>=', today()->subDays(7))
            ->orderBy('log_date')
            ->get();

        $totalSends = $healthLogs7d->sum('sends_today');
        $totalReplies = $healthLogs7d->sum('replies_today');
        $totalOpens = $healthLogs7d->sum('opens_today');
        $totalBounces = $healthLogs7d->sum('bounces_today');
        $totalSpam = $healthLogs7d->sum('spam_reports_today');

        $replyRate = $totalSends > 0 ? ($totalReplies / $totalSends) * 100 : 0;
        $openRate = $totalSends > 0 ? ($totalOpens / $totalSends) * 100 : 0;
        $bounceRate = $totalSends > 0 ? ($totalBounces / $totalSends) * 100 : 0;
        $spamRate = $totalSends > 0 ? ($totalSpam / $totalSends) * 100 : 0;

        // Hard bounces from BounceEvent table
        $hardBounces7d = BounceEvent::where('sender_mailbox_id', $sender->id)
            ->where('bounced_at', '>=', now()->subDays(7))
            ->where('bounce_type', 'hard')
            ->count();

        // Placement score from last test
        $lastPlacement = PlacementTest::where('sender_mailbox_id', $sender->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        // Reputation score
        $reputation = ReputationScore::where('sender_mailbox_id', $sender->id)
            ->orderByDesc('score_date')
            ->first();

        // Volume trend (is volume growing or shrinking?)
        $dailyVolumes = $healthLogs7d->pluck('sends_today')->toArray();
        $volumeTrend = $this->calculateTrend($dailyVolumes);

        // Health score trend
        $healthScores = $healthLogs7d->pluck('health_score')->filter()->toArray();
        $healthTrend = $this->calculateTrend($healthScores);

        return [
            'warmup_day' => $sender->current_warmup_day ?? 0,
            'daily_cap' => $sender->daily_send_cap,
            'sends_7d' => $totalSends,
            'reply_rate' => round($replyRate, 1),
            'open_rate' => round($openRate, 1),
            'bounce_rate' => round($bounceRate, 1),
            'spam_rate' => round($spamRate, 1),
            'hard_bounces_7d' => $hardBounces7d,
            'placement_score' => $lastPlacement?->placement_score,
            'reputation_score' => $reputation?->overall_score,
            'reputation_risk' => $reputation?->risk_level,
            'volume_trend' => $volumeTrend,
            'health_trend' => $healthTrend,
            'ramp_down_active' => $sender->ramp_down_active,
            'consecutive_clean_days' => $sender->consecutive_clean_days ?? 0,
            'days_active' => $healthLogs7d->count(),
        ];
    }

    private function determineRecommendation(array $metrics): string
    {
        // PAUSE: Critical thresholds
        if ($metrics['bounce_rate'] > 10 || $metrics['spam_rate'] > 5) {
            return 'pause';
        }
        if ($metrics['hard_bounces_7d'] > 5) {
            return 'pause';
        }
        if (($metrics['reputation_risk'] ?? 'low') === 'critical') {
            return 'pause';
        }

        // SLOW DOWN: Warning thresholds
        if ($metrics['bounce_rate'] > 3 || $metrics['spam_rate'] > 1) {
            return 'slow_down';
        }
        if (($metrics['placement_score'] ?? 100) < 40) {
            return 'slow_down';
        }
        if ($metrics['health_trend'] === 'declining') {
            return 'slow_down';
        }
        if (($metrics['reputation_risk'] ?? 'low') === 'high') {
            return 'slow_down';
        }

        // RAMP UP: Healthy signals
        if ($metrics['warmup_day'] >= 3 &&
            $metrics['bounce_rate'] < 1 &&
            $metrics['spam_rate'] < 0.5 &&
            $metrics['reply_rate'] > 10 &&
            $metrics['open_rate'] > 30 &&
            ($metrics['placement_score'] ?? 100) >= 70 &&
            $metrics['days_active'] >= 3
        ) {
            return 'ramp_up';
        }

        // RESUME: For paused/ramp-down senders with clean history
        if ($metrics['ramp_down_active'] && $metrics['consecutive_clean_days'] >= 3) {
            return 'resume';
        }

        return 'maintain';
    }

    private function calculateRecommendedCap(SenderMailbox $sender, array $metrics, string $recommendation): int
    {
        $currentCap = $sender->daily_send_cap;

        return match ($recommendation) {
            'ramp_up' => min($currentCap + max(1, (int)($currentCap * 0.20)), 100),
            'slow_down' => max(1, (int)($currentCap * 0.60)),
            'pause' => 0,
            'resume' => max(1, (int)($currentCap * 0.50)),
            default => $currentCap,
        };
    }

    private function buildReasoning(array $metrics, string $recommendation): string
    {
        $parts = [];

        switch ($recommendation) {
            case 'pause':
                if ($metrics['bounce_rate'] > 10) $parts[] = "Bounce rate {$metrics['bounce_rate']}% exceeds safe threshold";
                if ($metrics['spam_rate'] > 5) $parts[] = "Spam rate {$metrics['spam_rate']}% is dangerously high";
                if ($metrics['hard_bounces_7d'] > 5) $parts[] = "{$metrics['hard_bounces_7d']} hard bounces in 7 days";
                if (($metrics['reputation_risk'] ?? 'low') === 'critical') $parts[] = "Reputation risk is critical";
                break;

            case 'slow_down':
                if ($metrics['bounce_rate'] > 3) $parts[] = "Bounce rate {$metrics['bounce_rate']}% above comfort zone";
                if ($metrics['spam_rate'] > 1) $parts[] = "Spam rate {$metrics['spam_rate']}% needs attention";
                if (($metrics['placement_score'] ?? 100) < 40) $parts[] = "Placement score {$metrics['placement_score']}% is low";
                if ($metrics['health_trend'] === 'declining') $parts[] = "Health score trending downward";
                break;

            case 'ramp_up':
                $parts[] = "Day {$metrics['warmup_day']}, all metrics healthy";
                $parts[] = "Reply rate {$metrics['reply_rate']}%, open rate {$metrics['open_rate']}%";
                $parts[] = "Bounce rate {$metrics['bounce_rate']}% within safe range";
                break;

            case 'resume':
                $parts[] = "{$metrics['consecutive_clean_days']} consecutive clean days";
                $parts[] = "Safe to resume with reduced cap";
                break;

            default:
                $parts[] = "Metrics within normal range, maintaining current volume";
                break;
        }

        return implode('. ', $parts) . '.';
    }

    private function calculateTrend(array $values): string
    {
        if (count($values) < 3) return 'stable';

        $recent = array_slice($values, -3);
        $earlier = array_slice($values, 0, min(3, count($values) - 3));

        if (empty($earlier)) return 'stable';

        $recentAvg = array_sum($recent) / count($recent);
        $earlierAvg = array_sum($earlier) / count($earlier);

        if ($earlierAvg == 0) return 'stable';

        $change = (($recentAvg - $earlierAvg) / $earlierAvg) * 100;

        if ($change > 15) return 'improving';
        if ($change < -15) return 'declining';
        return 'stable';
    }
}
