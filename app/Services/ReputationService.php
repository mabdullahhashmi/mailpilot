<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\SenderMailbox;
use App\Models\ReputationScore;
use App\Models\DnsAuditLog;
use App\Models\BounceEvent;
use App\Models\MailboxHealthLog;
use App\Models\PlacementTest;
use App\Models\WarmupEvent;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReputationService
{
    /**
     * Calculate and store reputation score for a domain.
     */
    public function scoreDomain(Domain $domain): ReputationScore
    {
        $dnsScore = $this->calculateDnsScore($domain);
        $engagementScore = $this->calculateDomainEngagement($domain);
        $bounceScore = $this->calculateDomainBounceScore($domain);
        $placementScore = $this->calculateDomainPlacementScore($domain);
        $volumeScore = $this->calculateDomainVolumeScore($domain);

        // Weighted overall: DNS 25%, Engagement 25%, Bounce 25%, Placement 15%, Volume 10%
        $overall = (int) round(
            ($dnsScore * 0.25) +
            ($engagementScore * 0.25) +
            ($bounceScore * 0.25) +
            ($placementScore * 0.15) +
            ($volumeScore * 0.10)
        );

        $riskLevel = $this->determineRiskLevel($overall);

        return DB::transaction(function () use ($domain, $overall, $dnsScore, $engagementScore, $bounceScore, $placementScore, $volumeScore, $riskLevel) {
            $score = ReputationScore::updateOrCreate(
                [
                    'domain_id' => $domain->id,
                    'sender_mailbox_id' => null,
                    'score_date' => today(),
                ],
                [
                    'overall_score' => $overall,
                    'dns_score' => $dnsScore,
                    'engagement_score' => $engagementScore,
                    'bounce_score' => $bounceScore,
                    'placement_score' => $placementScore,
                    'volume_score' => $volumeScore,
                    'risk_level' => $riskLevel,
                    'breakdown' => [
                        'dns' => ['score' => $dnsScore, 'weight' => '25%'],
                        'engagement' => ['score' => $engagementScore, 'weight' => '25%'],
                        'bounce' => ['score' => $bounceScore, 'weight' => '25%'],
                        'placement' => ['score' => $placementScore, 'weight' => '15%'],
                        'volume' => ['score' => $volumeScore, 'weight' => '10%'],
                    ],
                ]
            );

            // Update domain fields
            $domain->update([
                'reputation_score' => $overall,
                'reputation_risk_level' => $riskLevel,
                'last_reputation_scan_at' => now(),
            ]);

            // Alert on high risk
            if ($riskLevel === 'critical' || $riskLevel === 'high') {
                SystemAlert::create([
                    'title' => "Domain reputation risk: {$domain->domain_name}",
                    'message' => "Reputation score: {$overall}/100 ({$riskLevel}). DNS: {$dnsScore}, Engagement: {$engagementScore}, Bounces: {$bounceScore}",
                    'severity' => $riskLevel === 'critical' ? 'critical' : 'warning',
                    'context_type' => 'domain',
                    'context_id' => $domain->id,
                ]);
            }

            return $score;
        });
    }

    /**
     * Calculate and store reputation score for a sender.
     */
    public function scoreSender(SenderMailbox $sender): ReputationScore
    {
        $domain = $sender->domain;

        $dnsScore = $domain ? $this->calculateDnsScore($domain) : 0;
        $engagementScore = $this->calculateSenderEngagement($sender);
        $bounceScore = $this->calculateSenderBounceScore($sender);
        $placementScore = $this->calculateSenderPlacementScore($sender);
        $volumeScore = $this->calculateSenderVolumeScore($sender);

        $overall = (int) round(
            ($dnsScore * 0.20) +
            ($engagementScore * 0.30) +
            ($bounceScore * 0.25) +
            ($placementScore * 0.15) +
            ($volumeScore * 0.10)
        );

        $riskLevel = $this->determineRiskLevel($overall);

        return DB::transaction(function () use ($domain, $sender, $overall, $dnsScore, $engagementScore, $bounceScore, $placementScore, $volumeScore, $riskLevel) {
            $score = ReputationScore::updateOrCreate(
                [
                    'domain_id' => $domain?->id,
                    'sender_mailbox_id' => $sender->id,
                    'score_date' => today(),
                ],
                [
                    'overall_score' => $overall,
                    'dns_score' => $dnsScore,
                    'engagement_score' => $engagementScore,
                    'bounce_score' => $bounceScore,
                    'placement_score' => $placementScore,
                    'volume_score' => $volumeScore,
                    'risk_level' => $riskLevel,
                    'breakdown' => [
                        'dns' => ['score' => $dnsScore, 'weight' => '20%'],
                        'engagement' => ['score' => $engagementScore, 'weight' => '30%'],
                        'bounce' => ['score' => $bounceScore, 'weight' => '25%'],
                        'placement' => ['score' => $placementScore, 'weight' => '15%'],
                        'volume' => ['score' => $volumeScore, 'weight' => '10%'],
                    ],
                ]
            );

            // Update sender fields
            $sender->update([
                'reputation_score' => $overall,
                'reputation_risk' => $riskLevel,
                'last_reputation_scan_at' => now(),
            ]);

            return $score;
        });
    }

    /**
     * Run full reputation scan for all domains and senders.
     */
    public function runFullScan(): array
    {
        $domainsScored = 0;
        $sendersScored = 0;

        $domains = Domain::with('senderMailboxes')->where('status', 'active')->get();
        foreach ($domains as $i => $domain) {
            try {
                $this->scoreDomain($domain);
                $domainsScored++;
                if (($i + 1) % 50 === 0) {
                    Log::info("[Reputation] Domain progress: " . ($i + 1) . "/{$domains->count()}");
                }
            } catch (\Throwable $e) {
                Log::warning("[Reputation] Failed to score domain {$domain->domain_name}: {$e->getMessage()}");
            }
        }

        $senders = SenderMailbox::with('domain')->where('status', 'active')->get();
        foreach ($senders as $i => $sender) {
            try {
                $this->scoreSender($sender);
                $sendersScored++;
                if (($i + 1) % 100 === 0) {
                    Log::info("[Reputation] Sender progress: " . ($i + 1) . "/{$senders->count()}");
                }
            } catch (\Throwable $e) {
                Log::warning("[Reputation] Failed to score sender {$sender->email_address}: {$e->getMessage()}");
            }
        }

        return ['domains_scored' => $domainsScored, 'senders_scored' => $sendersScored];
    }

    /**
     * Audit DNS changes for a domain and log any drifts.
     */
    public function auditDnsChanges(Domain $domain, array $previousState): int
    {
        $changesLogged = 0;
        $fields = ['spf_status', 'dkim_status', 'dmarc_status', 'mx_status'];

        foreach ($fields as $field) {
            $recordType = str_replace('_status', '', $field);
            $oldVal = $previousState[$field] ?? 'unknown';
            $newVal = $domain->$field;

            if ($oldVal !== $newVal) {
                DnsAuditLog::create([
                    'domain_id' => $domain->id,
                    'record_type' => $recordType,
                    'previous_status' => $oldVal,
                    'new_status' => $newVal,
                    'details' => "DNS {$recordType} changed from {$oldVal} to {$newVal}",
                ]);
                $changesLogged++;

                // Alert on degradation
                if ($oldVal === 'pass' && $newVal !== 'pass') {
                    SystemAlert::create([
                        'title' => "DNS degradation: {$domain->domain_name}",
                        'message' => strtoupper($recordType) . " record changed from {$oldVal} to {$newVal}. This may impact deliverability.",
                        'severity' => 'critical',
                        'context_type' => 'domain',
                        'context_id' => $domain->id,
                    ]);
                }
            }
        }

        return $changesLogged;
    }

    /**
     * Get DNS audit history for a domain.
     */
    public function getDnsAuditHistory(Domain $domain, int $days = 30): array
    {
        return DnsAuditLog::where('domain_id', $domain->id)
            ->where('created_at', '>=', now()->subDays($days))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($log) => [
                'record_type' => strtoupper($log->record_type),
                'previous' => $log->previous_status,
                'new' => $log->new_status,
                'details' => $log->details,
                'date' => $log->created_at->format('Y-m-d H:i'),
            ])
            ->toArray();
    }

    /**
     * Get reputation trend for a domain or sender.
     */
    public function getReputationTrend(?int $domainId, ?int $senderId, int $days = 30): array
    {
        $query = ReputationScore::query()
            ->where('score_date', '>=', today()->subDays($days))
            ->orderBy('score_date');

        if ($senderId) {
            $query->where('sender_mailbox_id', $senderId);
        } elseif ($domainId) {
            $query->where('domain_id', $domainId)->whereNull('sender_mailbox_id');
        }

        return $query->get()->map(fn ($s) => [
            'date' => $s->score_date->format('Y-m-d'),
            'overall' => $s->overall_score,
            'dns' => $s->dns_score,
            'engagement' => $s->engagement_score,
            'bounce' => $s->bounce_score,
            'placement' => $s->placement_score,
            'risk_level' => $s->risk_level,
        ])->toArray();
    }

    /**
     * Get overall reputation dashboard data.
     */
    public function getDashboardData(): array
    {
        $domainScores = ReputationScore::whereNull('sender_mailbox_id')
            ->where('score_date', today())
            ->get();

        $senderScores = ReputationScore::whereNotNull('sender_mailbox_id')
            ->where('score_date', today())
            ->get();

        $domains = Domain::where('status', 'active')
            ->orderBy('domain_name')
            ->get([
                'id',
                'domain_name',
                'reputation_score',
                'reputation_risk_level',
                'spf_status',
                'dkim_status',
                'dmarc_status',
                'mx_status',
                'last_reputation_scan_at',
            ]);

        $senders = SenderMailbox::where('status', 'active')
            ->orderBy('email_address')
            ->get([
                'id',
                'email_address',
                'reputation_score',
                'reputation_risk',
                'placement_score',
                'last_reputation_scan_at',
            ]);

        $domainScoreMap = $domainScores->keyBy('domain_id');
        $senderScoreMap = $senderScores->keyBy('sender_mailbox_id');

        $domainRows = $domains->map(function ($domain) use ($domainScoreMap) {
            $scoreRow = $domainScoreMap->get($domain->id);

            return [
                'id' => $domain->id,
                'name' => $domain->domain_name,
                'reputation_score' => $scoreRow
                    ? (int) $scoreRow->overall_score
                    : (int) ($domain->reputation_score ?? 0),
                'risk_level' => $scoreRow
                    ? $scoreRow->risk_level
                    : ($domain->reputation_risk_level ?? 'low'),
                'dns' => [
                    'spf' => $domain->spf_status,
                    'dkim' => $domain->dkim_status,
                    'dmarc' => $domain->dmarc_status,
                    'mx' => $domain->mx_status,
                ],
                'last_scan_at' => $domain->last_reputation_scan_at?->format('Y-m-d H:i'),
                'score_source' => $scoreRow ? 'reputation_scores_today' : 'domain_fallback',
            ];
        })->values();

        $senderRows = $senders->map(function ($sender) use ($senderScoreMap) {
            $scoreRow = $senderScoreMap->get($sender->id);

            return [
                'id' => $sender->id,
                'email' => $sender->email_address,
                'reputation_score' => $scoreRow
                    ? (int) $scoreRow->overall_score
                    : (int) ($sender->reputation_score ?? 0),
                'risk_level' => $scoreRow
                    ? $scoreRow->risk_level
                    : ($sender->reputation_risk ?? 'low'),
                'placement_score' => $sender->placement_score !== null ? (float) $sender->placement_score : null,
                'last_scan_at' => $sender->last_reputation_scan_at?->format('Y-m-d H:i'),
                'score_source' => $scoreRow ? 'reputation_scores_today' : 'sender_fallback',
            ];
        })->values();

        $domainAvg = $domainRows->avg('reputation_score') ?? 0;
        $senderAvg = $senderRows->avg('reputation_score') ?? 0;

        $domainRiskCounts = [
            'critical' => $domainRows->where('risk_level', 'critical')->count(),
            'high' => $domainRows->where('risk_level', 'high')->count(),
            'medium' => $domainRows->where('risk_level', 'medium')->count(),
            'low' => $domainRows->where('risk_level', 'low')->count(),
        ];

        $senderRiskCounts = [
            'critical' => $senderRows->where('risk_level', 'critical')->count(),
            'high' => $senderRows->where('risk_level', 'high')->count(),
            'medium' => $senderRows->where('risk_level', 'medium')->count(),
            'low' => $senderRows->where('risk_level', 'low')->count(),
        ];

        return [
            'domains' => [
                'count' => $domainRows->count(),
                'avg_score' => round($domainAvg, 1),
                'critical' => $domainRiskCounts['critical'],
                'high' => $domainRiskCounts['high'],
                'medium' => $domainRiskCounts['medium'],
                'low' => $domainRiskCounts['low'],
            ],
            'senders' => [
                'count' => $senderRows->count(),
                'avg_score' => round($senderAvg, 1),
                'critical' => $senderRiskCounts['critical'],
                'high' => $senderRiskCounts['high'],
                'medium' => $senderRiskCounts['medium'],
                'low' => $senderRiskCounts['low'],
            ],
            'domains_list' => $domainRows->toArray(),
            'senders_list' => $senderRows->toArray(),
            'score_source' => [
                'domain_scores_today' => $domainScores->count(),
                'sender_scores_today' => $senderScores->count(),
                'using_fallback_for_domains' => $domainRows->where('score_source', 'domain_fallback')->count(),
                'using_fallback_for_senders' => $senderRows->where('score_source', 'sender_fallback')->count(),
            ],
            'dns_changes_7d' => DnsAuditLog::where('created_at', '>=', now()->subDays(7))->count(),
            'recent_dns_alerts' => DnsAuditLog::where('previous_status', 'pass')
                ->where('new_status', '!=', 'pass')
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
        ];
    }

    // ── Private Score Calculators ──

    private function calculateDnsScore(Domain $domain): int
    {
        $score = 0;
        if ($domain->spf_status === 'pass') $score += 25;
        if ($domain->dkim_status === 'pass') $score += 25;
        if ($domain->dmarc_status === 'pass') $score += 25;
        if ($domain->mx_status === 'pass') $score += 25;
        return $score;
    }

    private function calculateDomainEngagement(Domain $domain): int
    {
        $senderIds = $domain->relationLoaded('senderMailboxes')
            ? $domain->senderMailboxes->pluck('id')
            : $domain->senderMailboxes()->pluck('id');
        if ($senderIds->isEmpty()) return 50;

        $logs = MailboxHealthLog::whereIn('sender_mailbox_id', $senderIds)
            ->where('log_date', '>=', today()->subDays(7))
            ->get();

        $totalSends = $logs->sum('sends_today');
        $totalReplies = $logs->sum('replies_today');
        $totalOpens = $logs->sum('opens_today');

        if ($totalSends === 0) return 50;

        $replyRate = $totalReplies / $totalSends;
        $openRate = $totalOpens / $totalSends;

        // Reply rate > 20% = excellent, Open rate > 50% = excellent
        $score = 30; // base
        $score += min(40, (int)($replyRate * 100));
        $score += min(30, (int)($openRate * 50));

        return min(100, max(0, $score));
    }

    private function calculateDomainBounceScore(Domain $domain): int
    {
        $senderIds = $domain->relationLoaded('senderMailboxes')
            ? $domain->senderMailboxes->pluck('id')
            : $domain->senderMailboxes()->pluck('id');
        if ($senderIds->isEmpty()) return 100;

        $bounces7d = BounceEvent::whereIn('sender_mailbox_id', $senderIds)
            ->where('bounced_at', '>=', now()->subDays(7))
            ->count();

        $hardBounces7d = BounceEvent::whereIn('sender_mailbox_id', $senderIds)
            ->where('bounced_at', '>=', now()->subDays(7))
            ->where('bounce_type', 'hard')
            ->count();

        // Update rolling 7d counters on domain
        $sends7d = MailboxHealthLog::whereIn('sender_mailbox_id', $senderIds)
            ->where('log_date', '>=', today()->subDays(7))
            ->sum('sends_today');

        $domain->update([
            'total_bounces_7d' => $bounces7d,
            'total_sends_7d' => $sends7d,
        ]);

        if ($sends7d === 0) return 80;

        $bounceRate = $bounces7d / $sends7d;
        $hardRate = $hardBounces7d / $sends7d;

        // 0% bounces = 100, >5% hard = 0
        $score = 100;
        $score -= min(50, (int)($bounceRate * 500));
        $score -= min(50, (int)($hardRate * 1000));

        return max(0, min(100, $score));
    }

    private function calculateDomainPlacementScore(Domain $domain): int
    {
        $senderIds = $domain->relationLoaded('senderMailboxes')
            ? $domain->senderMailboxes->pluck('id')
            : $domain->senderMailboxes()->pluck('id');
        if ($senderIds->isEmpty()) return 50;

        $recentTests = PlacementTest::whereIn('sender_mailbox_id', $senderIds)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(14))
            ->get();

        if ($recentTests->isEmpty()) return 50;

        return (int) round($recentTests->avg('placement_score'));
    }

    private function calculateDomainVolumeScore(Domain $domain): int
    {
        $senderIds = $domain->relationLoaded('senderMailboxes')
            ? $domain->senderMailboxes->pluck('id')
            : $domain->senderMailboxes()->pluck('id');
        if ($senderIds->isEmpty()) return 50;

        // Check for consistent sending (not erratic)
        $dailyVolumes = MailboxHealthLog::whereIn('sender_mailbox_id', $senderIds)
            ->where('log_date', '>=', today()->subDays(7))
            ->selectRaw('log_date, SUM(sends_today) as sends')
            ->groupBy('log_date')
            ->pluck('sends')
            ->toArray();

        if (count($dailyVolumes) < 2) return 50;

        $avg = array_sum($dailyVolumes) / count($dailyVolumes);
        if ($avg === 0) return 50;

        // Calculate coefficient of variation (lower = more consistent)
        $variance = array_sum(array_map(fn ($v) => pow($v - $avg, 2), $dailyVolumes)) / count($dailyVolumes);
        $cv = sqrt($variance) / $avg;

        // CV < 0.3 = excellent consistency, CV > 1.0 = poor
        if ($cv < 0.3) return 100;
        if ($cv < 0.5) return 80;
        if ($cv < 0.7) return 60;
        if ($cv < 1.0) return 40;
        return 20;
    }

    private function calculateSenderEngagement(SenderMailbox $sender): int
    {
        $logs = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', '>=', today()->subDays(7))
            ->get();

        $totalSends = $logs->sum('sends_today');
        $totalReplies = $logs->sum('replies_today');
        $totalOpens = $logs->sum('opens_today');

        if ($totalSends === 0) return 50;

        $replyRate = $totalReplies / $totalSends;
        $openRate = $totalOpens / $totalSends;

        $score = 30;
        $score += min(40, (int)($replyRate * 100));
        $score += min(30, (int)($openRate * 50));

        return min(100, max(0, $score));
    }

    private function calculateSenderBounceScore(SenderMailbox $sender): int
    {
        $bounces7d = BounceEvent::where('sender_mailbox_id', $sender->id)
            ->where('bounced_at', '>=', now()->subDays(7))
            ->count();

        $sends7d = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', '>=', today()->subDays(7))
            ->sum('sends_today');

        if ($sends7d === 0) return 80;

        $bounceRate = $bounces7d / $sends7d;
        $score = 100 - min(100, (int)($bounceRate * 500));

        return max(0, $score);
    }

    private function calculateSenderPlacementScore(SenderMailbox $sender): int
    {
        $recentTest = PlacementTest::where('sender_mailbox_id', $sender->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(14))
            ->orderByDesc('completed_at')
            ->first();

        return $recentTest ? (int) round($recentTest->placement_score) : 50;
    }

    private function calculateSenderVolumeScore(SenderMailbox $sender): int
    {
        $dailyVolumes = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', '>=', today()->subDays(7))
            ->pluck('sends_today')
            ->toArray();

        if (count($dailyVolumes) < 2) return 50;

        $avg = array_sum($dailyVolumes) / count($dailyVolumes);
        if ($avg === 0) return 50;

        $variance = array_sum(array_map(fn ($v) => pow($v - $avg, 2), $dailyVolumes)) / count($dailyVolumes);
        $cv = sqrt($variance) / $avg;

        if ($cv < 0.3) return 100;
        if ($cv < 0.5) return 80;
        if ($cv < 0.7) return 60;
        if ($cv < 1.0) return 40;
        return 20;
    }

    private function determineRiskLevel(int $score): string
    {
        if ($score >= 75) return 'low';
        if ($score >= 50) return 'medium';
        if ($score >= 25) return 'high';
        return 'critical';
    }
}
