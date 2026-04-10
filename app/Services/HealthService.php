<?php

namespace App\Services;

use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\Domain;
use App\Models\MailboxHealthLog;
use App\Models\WarmupEvent;

class HealthService
{
    /**
     * Record a successful send for sender health tracking.
     */
    public function recordSend(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('sends_today');
    }

    /**
     * Record a reply received by sender (positive signal).
     */
    public function recordReply(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('replies_today');
    }

    /**
     * Record a bounce for sender.
     */
    public function recordBounce(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('bounces_today');
    }

    /**
     * Record interaction for a seed with a domain.
     */
    public function recordSeedInteraction(SeedMailbox $seed, Domain $domain): void
    {
        $seed->increment('total_interactions');
    }

    /**
     * Calculate daily health score for a sender.
     * Score: 0-100.
     */
    public function calculateSenderHealthScore(SenderMailbox $sender): int
    {
        $log = $this->getOrCreateDailyLog($sender);

        $score = 50; // Base score

        // Sends completed successfully
        if ($log->sends_today > 0) {
            $score += 10;
        }

        // Reply rate bonus
        if ($log->sends_today > 0) {
            $replyRate = $log->replies_today / $log->sends_today;
            $score += min(20, (int)($replyRate * 50));
        }

        // Bounce penalty
        if ($log->sends_today > 0) {
            $bounceRate = $log->bounces_today / $log->sends_today;
            $score -= min(30, (int)($bounceRate * 100));
        }

        // Open rate bonus
        if ($log->sends_today > 0) {
            $openRate = ($log->opens_today ?? 0) / $log->sends_today;
            $score += min(20, (int)($openRate * 30));
        }

        return max(0, min(100, $score));
    }

    /**
     * Update daily health log and save the computed score.
     */
    public function updateDailyHealth(SenderMailbox $sender): MailboxHealthLog
    {
        $log = $this->getOrCreateDailyLog($sender);
        $score = $this->calculateSenderHealthScore($sender);

        $log->update(['health_score' => $score]);

        return $log;
    }

    private function getOrCreateDailyLog(SenderMailbox $sender): MailboxHealthLog
    {
        return MailboxHealthLog::firstOrCreate(
            [
                'sender_mailbox_id' => $sender->id,
                'log_date' => today(),
            ],
            [
                'health_score' => 50,
                'sends_today' => 0,
                'replies_today' => 0,
                'bounces_today' => 0,
                'opens_today' => 0,
                'spam_reports_today' => 0,
            ]
        );
    }

    /**
     * Record a spam report for sender.
     */
    public function recordSpamReport(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('spam_reports_today');
    }

    /**
     * Record an open event.
     */
    public function recordOpen(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('opens_today');
    }

    /**
     * Get sender's bounce rate for today.
     */
    public function getTodayBounceRate(SenderMailbox $sender): float
    {
        $log = $this->getOrCreateDailyLog($sender);
        if ($log->sends_today === 0) return 0;
        return ($log->bounces_today / $log->sends_today) * 100;
    }

    /**
     * Get sender's spam rate for today.
     */
    public function getTodaySpamRate(SenderMailbox $sender): float
    {
        $log = $this->getOrCreateDailyLog($sender);
        if ($log->sends_today === 0) return 0;
        return ($log->spam_reports_today / $log->sends_today) * 100;
    }

    /**
     * Get historical health trend for a sender (last N days).
     */
    public function getHealthTrend(SenderMailbox $sender, int $days = 14): array
    {
        return MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->where('log_date', '>=', today()->subDays($days))
            ->orderBy('log_date')
            ->get()
            ->map(fn ($log) => [
                'date' => $log->log_date->format('Y-m-d'),
                'health_score' => $log->health_score,
                'sends' => $log->sends_today,
                'replies' => $log->replies_today,
                'bounces' => $log->bounces_today,
                'opens' => $log->opens_today,
                'spam' => $log->spam_reports_today,
            ])
            ->toArray();
    }
}
