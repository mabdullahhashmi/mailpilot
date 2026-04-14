<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\FlowTestStep;
use App\Models\MailboxHealthLog;
use App\Models\SeedMailbox;
use App\Models\SenderMailbox;
use App\Models\ThreadMessage;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class HealthService
{
    /**
     * Backfill mailbox health logs from tracked thread messages.
     * Returns number of day buckets touched.
     */
    public function syncHealthLogsFromMessages(SenderMailbox $sender, int $days = 30): int
    {
        $from = now()->subDays(max(1, $days))->startOfDay();

        $rows = ThreadMessage::query()
            ->join('threads', 'thread_messages.thread_id', '=', 'threads.id')
            ->where('threads.sender_mailbox_id', $sender->id)
            ->whereNotNull('thread_messages.sent_at')
            ->where('thread_messages.sent_at', '>=', $from)
            ->selectRaw('DATE(thread_messages.sent_at) as activity_date')
            ->selectRaw("SUM(CASE WHEN thread_messages.actor_type = 'sender' THEN 1 ELSE 0 END) as sends")
            ->selectRaw("SUM(CASE WHEN thread_messages.actor_type = 'seed' THEN 1 ELSE 0 END) as replies")
            ->selectRaw("SUM(CASE WHEN thread_messages.actor_type = 'sender' AND thread_messages.delivery_state IN ('failed', 'bounced') THEN 1 ELSE 0 END) as bounces")
            ->selectRaw("SUM(CASE WHEN thread_messages.actor_type = 'sender' AND thread_messages.delivery_state = 'opened' THEN 1 ELSE 0 END) as opens")
            ->groupByRaw('DATE(thread_messages.sent_at)')
            ->orderByRaw('DATE(thread_messages.sent_at) DESC')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $touched = 0;

        foreach ($rows as $row) {
            $logDate = \Carbon\Carbon::parse($row->activity_date)->toDateString();

            $log = MailboxHealthLog::firstOrCreate(
                [
                    'sender_mailbox_id' => $sender->id,
                    'log_date' => $logDate,
                ],
                [
                    'warmup_day' => $this->resolveWarmupDay($sender),
                    'health_score' => 50,
                    'sends_today' => 0,
                    'replies_today' => 0,
                    'bounces_today' => 0,
                    'opens_today' => 0,
                    'spam_reports_today' => 0,
                    'failed_events' => 0,
                ]
            );

            $sends = max((int) ($log->sends_today ?? 0), (int) ($row->sends ?? 0));
            $replies = max((int) ($log->replies_today ?? 0), (int) ($row->replies ?? 0));
            $bounces = max((int) ($log->bounces_today ?? 0), (int) ($row->bounces ?? 0));
            $opens = max((int) ($log->opens_today ?? 0), (int) ($row->opens ?? 0));
            $failedEvents = max((int) ($log->failed_events ?? 0), $bounces);

            $log->update([
                'warmup_day' => max((int) ($log->warmup_day ?? 0), $this->resolveWarmupDay($sender)),
                'sends_today' => $sends,
                'replies_today' => $replies,
                'bounces_today' => $bounces,
                'opens_today' => $opens,
                'failed_events' => $failedEvents,
            ]);

            $breakdown = $this->calculateSenderHealthBreakdown($sender, $log->fresh());
            $score = (int) ($breakdown['score'] ?? 0);

            if ((int) ($log->health_score ?? -1) !== $score) {
                $log->update(['health_score' => $score]);
            }

            $touched++;
        }

        $latestLog = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->orderBy('log_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestLog && (int) ($sender->health_score ?? -1) !== (int) $latestLog->health_score) {
            $sender->update(['health_score' => (int) $latestLog->health_score]);
        }

        return $touched;
    }

    /**
     * Backfill mailbox health logs from Flow Test activity.
     * This captures actions executed via Test Campaigns (flow_test_runs/steps).
     */
    public function syncHealthLogsFromFlowTests(SenderMailbox $sender, int $days = 30): int
    {
        $from = now()->subDays(max(1, $days))->startOfDay();

        $rows = FlowTestStep::query()
            ->join('flow_test_runs', 'flow_test_steps.flow_test_run_id', '=', 'flow_test_runs.id')
            ->where('flow_test_runs.sender_mailbox_id', $sender->id)
            ->whereNotNull('flow_test_steps.executed_at')
            ->where('flow_test_steps.executed_at', '>=', $from)
            ->selectRaw('DATE(flow_test_steps.executed_at) as activity_date')
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'completed' AND flow_test_steps.action_type IN ('sender_send_initial','sender_reply') THEN 1 ELSE 0 END) as sends")
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'completed' AND flow_test_steps.action_type = 'seed_reply' THEN 1 ELSE 0 END) as replies")
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'completed' AND flow_test_steps.action_type = 'seed_open_email' THEN 1 ELSE 0 END) as opens")
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'failed' AND flow_test_steps.action_type IN ('sender_send_initial','sender_reply') THEN 1 ELSE 0 END) as bounces")
            ->selectRaw("SUM(CASE WHEN flow_test_steps.status = 'failed' THEN 1 ELSE 0 END) as failed_events")
            ->groupByRaw('DATE(flow_test_steps.executed_at)')
            ->orderByRaw('DATE(flow_test_steps.executed_at) DESC')
            ->get();

        if ($rows->isEmpty()) {
            return 0;
        }

        $touched = 0;

        foreach ($rows as $row) {
            $logDate = \Carbon\Carbon::parse($row->activity_date)->toDateString();

            $log = MailboxHealthLog::firstOrCreate(
                [
                    'sender_mailbox_id' => $sender->id,
                    'log_date' => $logDate,
                ],
                [
                    'warmup_day' => $this->resolveWarmupDay($sender),
                    'health_score' => 50,
                    'sends_today' => 0,
                    'replies_today' => 0,
                    'bounces_today' => 0,
                    'opens_today' => 0,
                    'spam_reports_today' => 0,
                    'failed_events' => 0,
                ]
            );

            $sends = max((int) ($log->sends_today ?? 0), (int) ($row->sends ?? 0));
            $replies = max((int) ($log->replies_today ?? 0), (int) ($row->replies ?? 0));
            $opens = max((int) ($log->opens_today ?? 0), (int) ($row->opens ?? 0));
            $bounces = max((int) ($log->bounces_today ?? 0), (int) ($row->bounces ?? 0));
            $failedEvents = max((int) ($log->failed_events ?? 0), (int) ($row->failed_events ?? 0), $bounces);

            $log->update([
                'warmup_day' => max((int) ($log->warmup_day ?? 0), $this->resolveWarmupDay($sender)),
                'sends_today' => $sends,
                'replies_today' => $replies,
                'opens_today' => $opens,
                'bounces_today' => $bounces,
                'failed_events' => $failedEvents,
            ]);

            $breakdown = $this->calculateSenderHealthBreakdown($sender, $log->fresh());
            $score = (int) ($breakdown['score'] ?? 0);

            if ((int) ($log->health_score ?? -1) !== $score) {
                $log->update(['health_score' => $score]);
            }

            $touched++;
        }

        $latestLog = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->orderBy('log_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestLog && (int) ($sender->health_score ?? -1) !== (int) $latestLog->health_score) {
            $sender->update(['health_score' => (int) $latestLog->health_score]);
        }

        return $touched;
    }

    /**
     * Backfill mailbox health logs from real mailbox (IMAP) activity.
     * This captures manual/external sending activity outside warmup events.
     */
    public function syncHealthLogsFromImap(SenderMailbox $sender, int $days = 30): int
    {
        if (!function_exists('imap_open')) {
            return 0;
        }

        $imap = $this->connectImap($sender);
        if (!$imap) {
            return 0;
        }

        $since = now()->subDays(max(1, $days))->format('d-M-Y');
        $activity = [];
        $basePath = $this->imapPath($sender);

        try {
            $sentFolders = [
                'INBOX.Sent',
                'Sent',
                'Sent Items',
                'Sent Mail',
                '[Gmail]/Sent Mail',
                'INBOX.Sent Items',
            ];

            foreach ($sentFolders as $folder) {
                if (!@imap_reopen($imap, $basePath . $folder)) {
                    continue;
                }

                $uids = @imap_search($imap, 'SINCE "' . $since . '"', SE_UID) ?: [];
                $uids = array_slice($uids, -1200);

                foreach ($uids as $uid) {
                    $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
                    if (!$overview || empty($overview[0])) {
                        continue;
                    }

                    $date = $this->extractOverviewDate($overview[0]);
                    if (!$date) {
                        continue;
                    }

                    $activity[$date]['sends'] = (int) (($activity[$date]['sends'] ?? 0) + 1);
                }

                // Use first valid Sent folder to avoid double-counting aliases.
                break;
            }

            if (@imap_reopen($imap, $basePath . 'INBOX')) {
                $uids = @imap_search($imap, 'SINCE "' . $since . '"', SE_UID) ?: [];
                $uids = array_slice($uids, -1200);

                $selfEmail = strtolower($sender->email_address);

                foreach ($uids as $uid) {
                    $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
                    if (!$overview || empty($overview[0])) {
                        continue;
                    }

                    $entry = $overview[0];
                    $date = $this->extractOverviewDate($entry);
                    if (!$date) {
                        continue;
                    }

                    $from = strtolower((string) ($entry->from ?? ''));
                    $subject = strtolower((string) ($entry->subject ?? ''));

                    if ($from !== '' && str_contains($from, $selfEmail)) {
                        continue;
                    }

                    if ($this->isBounceLikeMessage($from, $subject)) {
                        $activity[$date]['bounces'] = (int) (($activity[$date]['bounces'] ?? 0) + 1);
                        $activity[$date]['failed_events'] = (int) (($activity[$date]['failed_events'] ?? 0) + 1);
                        continue;
                    }

                    // Treat inbound non-bounce emails as engagement/replies.
                    $activity[$date]['replies'] = (int) (($activity[$date]['replies'] ?? 0) + 1);
                }
            }
        } finally {
            @imap_close($imap);
        }

        if (empty($activity)) {
            return 0;
        }

        $touched = 0;
        krsort($activity);

        foreach ($activity as $logDate => $counts) {
            $log = MailboxHealthLog::firstOrCreate(
                [
                    'sender_mailbox_id' => $sender->id,
                    'log_date' => $logDate,
                ],
                [
                    'warmup_day' => $this->resolveWarmupDay($sender),
                    'health_score' => 50,
                    'sends_today' => 0,
                    'replies_today' => 0,
                    'bounces_today' => 0,
                    'opens_today' => 0,
                    'spam_reports_today' => 0,
                    'failed_events' => 0,
                ]
            );

            $sends = max((int) ($log->sends_today ?? 0), (int) ($counts['sends'] ?? 0));
            $replies = max((int) ($log->replies_today ?? 0), (int) ($counts['replies'] ?? 0));
            $bounces = max((int) ($log->bounces_today ?? 0), (int) ($counts['bounces'] ?? 0));
            $failedEvents = max((int) ($log->failed_events ?? 0), (int) ($counts['failed_events'] ?? 0), $bounces);

            $log->update([
                'warmup_day' => max((int) ($log->warmup_day ?? 0), $this->resolveWarmupDay($sender)),
                'sends_today' => $sends,
                'replies_today' => $replies,
                'bounces_today' => $bounces,
                'failed_events' => $failedEvents,
            ]);

            $breakdown = $this->calculateSenderHealthBreakdown($sender, $log->fresh());
            $score = (int) ($breakdown['score'] ?? 0);

            if ((int) ($log->health_score ?? -1) !== $score) {
                $log->update(['health_score' => $score]);
            }

            $touched++;
        }

        $latestLog = MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->orderBy('log_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($latestLog && (int) ($sender->health_score ?? -1) !== (int) $latestLog->health_score) {
            $sender->update(['health_score' => (int) $latestLog->health_score]);
        }

        return $touched;
    }

    /**
     * Record a successful send for sender health tracking.
     */
    public function recordSend(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('sends_today');
        $this->updateDailyHealth($sender);
    }

    /**
     * Record a reply received by sender (positive signal).
     */
    public function recordReply(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('replies_today');
        $this->updateDailyHealth($sender);
    }

    /**
     * Record a bounce for sender.
     */
    public function recordBounce(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('bounces_today');
        $log->increment('failed_events');
        $this->updateDailyHealth($sender);
    }

    /**
     * Record interaction for a seed with a domain.
     */
    public function recordSeedInteraction(SeedMailbox $seed, Domain $domain): void
    {
        $seed->increment('total_opens');
    }

    /**
     * Calculate daily health score for a sender.
     * Score: 0-100.
     */
    public function calculateSenderHealthScore(SenderMailbox $sender): int
    {
        return (int) ($this->calculateSenderHealthBreakdown($sender)['score'] ?? 0);
    }

    /**
     * Build a detailed health breakdown for a sender from latest or provided log.
     */
    public function calculateSenderHealthBreakdown(SenderMailbox $sender, ?MailboxHealthLog $log = null): array
    {
        $log = $log ?: MailboxHealthLog::where('sender_mailbox_id', $sender->id)
            ->orderBy('log_date', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if (!$log) {
            return [
                'score' => 0,
                'metrics' => [
                    'sends_today' => 0,
                    'replies_today' => 0,
                    'bounces_today' => 0,
                    'opens_today' => 0,
                    'spam_reports_today' => 0,
                    'failed_events' => 0,
                    'reply_rate' => 0,
                    'bounce_rate' => 0,
                    'open_rate' => 0,
                    'spam_rate' => 0,
                ],
                'increasing_points' => [],
                'decreasing_points' => [],
                'score_breakdown' => [
                    [
                        'label' => 'Base score',
                        'points' => 0,
                        'type' => 'neutral',
                        'detail' => 'No health logs available yet.',
                    ],
                ],
            ];
        }

        $sends = (int) ($log->sends_today ?? 0);
        $replies = (int) ($log->replies_today ?? 0);
        $bounces = (int) ($log->bounces_today ?? 0);
        $opens = (int) ($log->opens_today ?? 0);
        $spamReports = (int) ($log->spam_reports_today ?? 0);
        $failedEvents = (int) ($log->failed_events ?? 0);

        $replyRate = $sends > 0 ? ($replies / $sends) : 0.0;
        $bounceRate = $sends > 0 ? ($bounces / $sends) : 0.0;
        $openRate = $sends > 0 ? ($opens / $sends) : 0.0;
        $spamRate = $sends > 0 ? ($spamReports / $sends) : 0.0;

        $baseScore = 50;
        $sendBonus = $sends > 0 ? 10 : 0;
        $replyBonus = $sends > 0 ? min(20, (int) round($replyRate * 50)) : 0;
        $openBonus = $sends > 0 ? min(20, (int) round($openRate * 30)) : 0;
        $bouncePenalty = $sends > 0
            ? min(30, (int) round($bounceRate * 100))
            : min(10, $bounces * 2);
        $spamPenalty = $sends > 0
            ? min(20, (int) round($spamRate * 120))
            : min(10, $spamReports * 2);
        $failurePenalty = min(15, $failedEvents * 2);

        $rawScore = $baseScore + $sendBonus + $replyBonus + $openBonus - $bouncePenalty - $spamPenalty - $failurePenalty;
        $score = max(0, min(100, (int) round($rawScore)));

        $increasingPoints = [];
        $decreasingPoints = [];
        $scoreBreakdown = [
            [
                'label' => 'Base score',
                'points' => $baseScore,
                'type' => 'neutral',
                'detail' => 'Starting baseline before positive/negative signals.',
            ],
        ];

        if ($sendBonus > 0) {
            $increasingPoints[] = [
                'title' => 'Consistent sends completed',
                'points' => $sendBonus,
                'detail' => "{$sends} sends completed today.",
            ];
            $scoreBreakdown[] = [
                'label' => 'Send activity',
                'points' => $sendBonus,
                'type' => 'positive',
                'detail' => "{$sends} sends completed.",
            ];
        }

        if ($replyBonus > 0) {
            $increasingPoints[] = [
                'title' => 'Reply rate improved',
                'points' => $replyBonus,
                'detail' => 'Reply rate: ' . round($replyRate * 100, 1) . '%.',
            ];
            $scoreBreakdown[] = [
                'label' => 'Reply performance',
                'points' => $replyBonus,
                'type' => 'positive',
                'detail' => 'Reply rate at ' . round($replyRate * 100, 1) . '%.',
            ];
        }

        if ($openBonus > 0) {
            $increasingPoints[] = [
                'title' => 'Open engagement improved',
                'points' => $openBonus,
                'detail' => 'Open rate: ' . round($openRate * 100, 1) . '%.',
            ];
            $scoreBreakdown[] = [
                'label' => 'Open engagement',
                'points' => $openBonus,
                'type' => 'positive',
                'detail' => 'Open rate at ' . round($openRate * 100, 1) . '%.',
            ];
        }

        if ($bouncePenalty > 0) {
            $decreasingPoints[] = [
                'title' => 'Bounce impact',
                'points' => $bouncePenalty,
                'detail' => 'Bounce rate: ' . round($bounceRate * 100, 1) . '% (' . $bounces . ' bounces).',
            ];
            $scoreBreakdown[] = [
                'label' => 'Bounce penalty',
                'points' => -$bouncePenalty,
                'type' => 'negative',
                'detail' => 'Bounces reduced trust signals.',
            ];
        }

        if ($spamPenalty > 0) {
            $decreasingPoints[] = [
                'title' => 'Spam complaint impact',
                'points' => $spamPenalty,
                'detail' => 'Spam report rate: ' . round($spamRate * 100, 1) . '%.',
            ];
            $scoreBreakdown[] = [
                'label' => 'Spam penalty',
                'points' => -$spamPenalty,
                'type' => 'negative',
                'detail' => 'Spam reports reduced sender reputation.',
            ];
        }

        if ($failurePenalty > 0) {
            $decreasingPoints[] = [
                'title' => 'Execution failures',
                'points' => $failurePenalty,
                'detail' => "{$failedEvents} failed events recorded.",
            ];
            $scoreBreakdown[] = [
                'label' => 'Failure penalty',
                'points' => -$failurePenalty,
                'type' => 'negative',
                'detail' => "{$failedEvents} failed events.",
            ];
        }

        return [
            'score' => $score,
            'metrics' => [
                'sends_today' => $sends,
                'replies_today' => $replies,
                'bounces_today' => $bounces,
                'opens_today' => $opens,
                'spam_reports_today' => $spamReports,
                'failed_events' => $failedEvents,
                'reply_rate' => round($replyRate * 100, 1),
                'bounce_rate' => round($bounceRate * 100, 1),
                'open_rate' => round($openRate * 100, 1),
                'spam_rate' => round($spamRate * 100, 1),
            ],
            'increasing_points' => $increasingPoints,
            'decreasing_points' => $decreasingPoints,
            'score_breakdown' => $scoreBreakdown,
        ];
    }

    /**
     * Update daily health log and save the computed score.
     */
    public function updateDailyHealth(SenderMailbox $sender): MailboxHealthLog
    {
        $log = $this->getOrCreateDailyLog($sender);
        $breakdown = $this->calculateSenderHealthBreakdown($sender, $log);
        $score = (int) ($breakdown['score'] ?? 0);

        $log->update([
            'health_score' => $score,
            'warmup_day' => $this->resolveWarmupDay($sender),
        ]);

        if ((int) ($sender->health_score ?? -1) !== $score) {
            $sender->update(['health_score' => $score]);
        }

        return $log->fresh();
    }

    /**
     * Record a spam report for sender.
     */
    public function recordSpamReport(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('spam_reports_today');
        $log->increment('failed_events');
        $this->updateDailyHealth($sender);
    }

    /**
     * Record an open event.
     */
    public function recordOpen(SenderMailbox $sender): void
    {
        $log = $this->getOrCreateDailyLog($sender);
        $log->increment('opens_today');
        $this->updateDailyHealth($sender);
    }

    /**
     * Get sender's bounce rate for today.
     */
    public function getTodayBounceRate(SenderMailbox $sender): float
    {
        $log = $this->getOrCreateDailyLog($sender);
        if ($log->sends_today === 0) {
            return 0;
        }

        return ($log->bounces_today / $log->sends_today) * 100;
    }

    /**
     * Get sender's spam rate for today.
     */
    public function getTodaySpamRate(SenderMailbox $sender): float
    {
        $log = $this->getOrCreateDailyLog($sender);
        if ($log->sends_today === 0) {
            return 0;
        }

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
            ->map(function ($log) use ($sender) {
                $score = (int) ($this->calculateSenderHealthBreakdown($sender, $log)['score'] ?? 0);

                return [
                    'date' => $log->log_date->format('Y-m-d'),
                    'health_score' => $score,
                    'sends' => (int) ($log->sends_today ?? 0),
                    'replies' => (int) ($log->replies_today ?? 0),
                    'bounces' => (int) ($log->bounces_today ?? 0),
                    'opens' => (int) ($log->opens_today ?? 0),
                    'spam' => (int) ($log->spam_reports_today ?? 0),
                ];
            })
            ->toArray();
    }

    private function getOrCreateDailyLog(SenderMailbox $sender): MailboxHealthLog
    {
        return MailboxHealthLog::firstOrCreate(
            [
                'sender_mailbox_id' => $sender->id,
                'log_date' => today(),
            ],
            [
                'warmup_day' => $this->resolveWarmupDay($sender),
                'health_score' => 50,
                'sends_today' => 0,
                'replies_today' => 0,
                'bounces_today' => 0,
                'opens_today' => 0,
                'spam_reports_today' => 0,
                'failed_events' => 0,
            ]
        );
    }

    private function resolveWarmupDay(SenderMailbox $sender): int
    {
        $day = (int) ($sender->current_warmup_day ?? 0);
        return $day > 0 ? $day : 1;
    }

    private function connectImap(SenderMailbox $sender): mixed
    {
        $host = $sender->imap_host ?: $sender->smtp_host;
        $port = (int) ($sender->imap_port ?: 993);
        $encryption = $sender->imap_encryption ?: 'ssl';
        $username = $sender->imap_username ?: $sender->smtp_username;
        $passwordCipher = $sender->imap_password ?: $sender->smtp_password;

        if (!$host || !$username || !$passwordCipher) {
            return false;
        }

        $password = null;
        try {
            $password = Crypt::decryptString($passwordCipher);
        } catch (\Throwable $e) {
            // Some installs may store plain credentials.
            $password = $passwordCipher;
        }

        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        $path = "{{$host}:{$port}{$flags}}INBOX";

        try {
            $imap = @imap_open($path, $username, $password, 0, 1);
            if (!$imap) {
                return false;
            }

            return $imap;
        } catch (\Throwable $e) {
            Log::warning("Sender health IMAP connect failed for {$sender->email_address}: {$e->getMessage()}");
            return false;
        }
    }

    private function imapPath(SenderMailbox $sender): string
    {
        $host = $sender->imap_host ?: $sender->smtp_host;
        $port = (int) ($sender->imap_port ?: 993);
        $encryption = $sender->imap_encryption ?: 'ssl';
        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';

        return "{{$host}:{$port}{$flags}}";
    }

    private function extractOverviewDate(object $overview): ?string
    {
        $rawDate = $overview->date ?? null;
        if (!$rawDate) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($rawDate)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isBounceLikeMessage(string $from, string $subject): bool
    {
        $haystack = strtolower(trim($from . ' ' . $subject));
        if ($haystack === '') {
            return false;
        }

        $keywords = [
            'mailer-daemon',
            'postmaster',
            'delivery status notification',
            'mail delivery subsystem',
            'undeliverable',
            'delivery failed',
            'failure notice',
            'returned to sender',
        ];

        foreach ($keywords as $keyword) {
            if (str_contains($haystack, $keyword)) {
                return true;
            }
        }

        return false;
    }
}
