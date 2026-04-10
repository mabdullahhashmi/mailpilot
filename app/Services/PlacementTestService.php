<?php

namespace App\Services;

use App\Models\PlacementTest;
use App\Models\PlacementResult;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;
use App\Models\MailboxHealthLog;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class PlacementTestService
{
    /**
     * Run an inbox placement test for a sender against all active seeds.
     */
    public function runTest(SenderMailbox $sender): PlacementTest
    {
        $domain = $sender->domain;

        $test = PlacementTest::create([
            'sender_mailbox_id' => $sender->id,
            'domain_id' => $domain?->id,
            'status' => 'running',
            'started_at' => now(),
        ]);

        try {
            $seeds = SeedMailbox::where('status', 'active')
                ->whereNotNull('imap_host')
                ->whereNotNull('imap_password')
                ->take(20)
                ->get();

            if ($seeds->isEmpty()) {
                $test->update([
                    'status' => 'failed',
                    'failure_reason' => 'No active seeds with IMAP credentials available',
                    'completed_at' => now(),
                ]);
                return $test;
            }

            // Send a test probe email to each seed
            $probeSubject = 'Re: Quick follow-up [PT-' . $test->id . '-' . substr(md5($test->id . now()->timestamp), 0, 6) . ']';
            $probeBody = $this->generateProbeBody();

            $sentToSeeds = [];
            foreach ($seeds as $seed) {
                try {
                    $this->sendProbe($sender, $seed, $probeSubject, $probeBody);
                    $sentToSeeds[] = $seed;
                } catch (\Throwable $e) {
                    Log::warning("[PlacementTest] Failed to send probe to {$seed->email_address}: {$e->getMessage()}");
                    PlacementResult::create([
                        'placement_test_id' => $test->id,
                        'seed_mailbox_id' => $seed->id,
                        'result' => 'error',
                        'provider' => $this->detectProvider($seed->email_address),
                    ]);
                }
            }

            // Wait briefly for delivery then check each seed's inbox via IMAP
            // In production this runs as a scheduled follow-up
            $test->update(['seeds_tested' => count($sentToSeeds)]);

            // Check placement after a delay (2 minutes for delivery)
            $this->checkPlacements($test, $sentToSeeds, $probeSubject);

            // Calculate final scores
            $this->finalizeTest($test);

            return $test->fresh();

        } catch (\Throwable $e) {
            $test->update([
                'status' => 'failed',
                'failure_reason' => substr($e->getMessage(), 0, 255),
                'completed_at' => now(),
            ]);
            Log::error("[PlacementTest] Test #{$test->id} failed: {$e->getMessage()}");
            return $test;
        }
    }

    /**
     * Check placement results via IMAP for each seed.
     */
    public function checkPlacements(PlacementTest $test, array $seeds, string $probeSubject): void
    {
        $searchTag = $this->extractSearchTag($probeSubject);

        foreach ($seeds as $seed) {
            // Skip if already has a result
            if (PlacementResult::where('placement_test_id', $test->id)
                ->where('seed_mailbox_id', $seed->id)->exists()) {
                continue;
            }

            try {
                $result = $this->checkSeedInbox($seed, $searchTag);

                PlacementResult::create([
                    'placement_test_id' => $test->id,
                    'seed_mailbox_id' => $seed->id,
                    'result' => $result['placement'],
                    'provider' => $this->detectProvider($seed->email_address),
                    'delivery_time_seconds' => $result['delivery_time'] ?? null,
                    'headers_snippet' => $result['headers'] ?? null,
                ]);
            } catch (\Throwable $e) {
                Log::warning("[PlacementTest] IMAP check failed for {$seed->email_address}: {$e->getMessage()}");
                PlacementResult::create([
                    'placement_test_id' => $test->id,
                    'seed_mailbox_id' => $seed->id,
                    'result' => 'missing',
                    'provider' => $this->detectProvider($seed->email_address),
                ]);
            }
        }
    }

    /**
     * Finalize test: calculate scores and update sender.
     */
    public function finalizeTest(PlacementTest $test): void
    {
        $results = PlacementResult::where('placement_test_id', $test->id)->get();

        $inbox = $results->where('result', 'inbox')->count();
        $spam = $results->where('result', 'spam')->count();
        $missing = $results->where('result', 'missing')->count();
        $total = $results->whereIn('result', ['inbox', 'spam', 'missing'])->count();

        $score = $total > 0 ? round(($inbox / $total) * 100, 2) : 0;

        $test->update([
            'status' => 'completed',
            'inbox_count' => $inbox,
            'spam_count' => $spam,
            'missing_count' => $missing,
            'placement_score' => $score,
            'completed_at' => now(),
        ]);

        // Update sender's placement score
        $sender = $test->senderMailbox;
        if ($sender) {
            $sender->update([
                'placement_score' => $score,
                'last_placement_test_at' => now(),
            ]);
        }

        // Update daily health log
        $healthLog = MailboxHealthLog::where('sender_mailbox_id', $test->sender_mailbox_id)
            ->where('log_date', today())
            ->first();
        if ($healthLog) {
            $healthLog->update([
                'placement_inbox' => $inbox,
                'placement_spam' => $spam,
                'placement_missing' => $missing,
            ]);
        }

        // Alert on poor placement
        if ($score < 50 && $total >= 3) {
            SystemAlert::create([
                'title' => 'Poor Inbox Placement',
                'message' => "Sender {$sender->email_address} placement score: {$score}% ({$inbox} inbox, {$spam} spam, {$missing} missing out of {$total} seeds)",
                'severity' => $score < 25 ? 'critical' : 'warning',
                'context_type' => 'sender_mailbox',
                'context_id' => $sender->id,
            ]);
        }
    }

    /**
     * Get placement trend for a sender (last N tests).
     */
    public function getPlacementTrend(SenderMailbox $sender, int $limit = 14): array
    {
        return PlacementTest::where('sender_mailbox_id', $sender->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->take($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn ($t) => [
                'date' => $t->completed_at->format('Y-m-d'),
                'score' => (float) $t->placement_score,
                'inbox' => $t->inbox_count,
                'spam' => $t->spam_count,
                'missing' => $t->missing_count,
            ])
            ->toArray();
    }

    /**
     * Get aggregate placement stats across all senders.
     */
    public function getOverallStats(): array
    {
        $recent = PlacementTest::where('status', 'completed')
            ->where('completed_at', '>=', now()->subDays(7))
            ->get();

        if ($recent->isEmpty()) {
            return [
                'avg_score' => 0,
                'total_tests' => 0,
                'total_inbox' => 0,
                'total_spam' => 0,
                'total_missing' => 0,
                'senders_below_50' => 0,
            ];
        }

        $sendersBelowThreshold = SenderMailbox::where('placement_score', '<', 50)
            ->whereNotNull('placement_score')
            ->count();

        return [
            'avg_score' => round($recent->avg('placement_score'), 1),
            'total_tests' => $recent->count(),
            'total_inbox' => $recent->sum('inbox_count'),
            'total_spam' => $recent->sum('spam_count'),
            'total_missing' => $recent->sum('missing_count'),
            'senders_below_50' => $sendersBelowThreshold,
        ];
    }

    /**
     * Check a seed's IMAP inbox and spam folder for a specific probe.
     */
    private function checkSeedInbox(SeedMailbox $seed, string $searchTag): array
    {
        $imap = $this->connectImap($seed);
        if (!$imap) {
            return ['placement' => 'missing', 'delivery_time' => null, 'headers' => null];
        }

        try {
            // Search INBOX
            $inboxResults = @imap_search($imap, 'SUBJECT "' . $searchTag . '"', SE_UID);
            if ($inboxResults && count($inboxResults) > 0) {
                $headers = @imap_fetchheader($imap, $inboxResults[0], FT_UID);
                @imap_close($imap);
                return [
                    'placement' => 'inbox',
                    'delivery_time' => null,
                    'headers' => substr($headers ?: '', 0, 500),
                ];
            }

            // Search Spam/Junk folder
            $spamFolders = ['[Gmail]/Spam', 'Junk', 'Spam', 'INBOX.Spam', 'INBOX.Junk', 'Junk E-mail'];
            foreach ($spamFolders as $folder) {
                if (@imap_reopen($imap, $this->getImapPath($seed) . $folder)) {
                    $spamResults = @imap_search($imap, 'SUBJECT "' . $searchTag . '"', SE_UID);
                    if ($spamResults && count($spamResults) > 0) {
                        $headers = @imap_fetchheader($imap, $spamResults[0], FT_UID);
                        @imap_close($imap);
                        return [
                            'placement' => 'spam',
                            'delivery_time' => null,
                            'headers' => substr($headers ?: '', 0, 500),
                        ];
                    }
                }
            }

            @imap_close($imap);
            return ['placement' => 'missing', 'delivery_time' => null, 'headers' => null];

        } catch (\Throwable $e) {
            @imap_close($imap);
            throw $e;
        }
    }

    private function sendProbe(SenderMailbox $sender, SeedMailbox $seed, string $subject, string $body): void
    {
        $password = Crypt::decryptString($sender->smtp_password);

        $transport = new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
            $sender->smtp_host,
            $sender->smtp_port,
            $sender->smtp_encryption === 'tls'
        );
        $transport->setUsername($sender->smtp_username);
        $transport->setPassword($password);

        $email = (new \Symfony\Component\Mime\Email())
            ->from($sender->email_address)
            ->to($seed->email_address)
            ->subject($subject)
            ->html($body);

        $mailer = new \Symfony\Component\Mailer\Mailer($transport);
        $mailer->send($email);
    }

    private function connectImap(SeedMailbox $seed): mixed
    {
        $host = $seed->imap_host;
        $port = $seed->imap_port ?? 993;
        $encryption = $seed->imap_encryption ?? 'ssl';

        try {
            $password = Crypt::decryptString($seed->imap_password);
        } catch (\Exception $e) {
            return false;
        }

        $username = $seed->imap_username ?? $seed->email_address;
        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        $path = "{{$host}:{$port}{$flags}}INBOX";

        try {
            $imap = @imap_open($path, $username, $password, 0, 1);
            return $imap ?: false;
        } catch (\Exception $e) {
            return false;
        }
    }

    private function getImapPath(SeedMailbox $seed): string
    {
        $host = $seed->imap_host;
        $port = $seed->imap_port ?? 993;
        $encryption = $seed->imap_encryption ?? 'ssl';
        $flags = $encryption === 'ssl' ? '/imap/ssl/novalidate-cert' : '/imap/notls';
        return "{{$host}:{$port}{$flags}}";
    }

    private function generateProbeBody(): string
    {
        $bodies = [
            '<p>Hey, just wanted to check in and see if you got my last message. Let me know when you have a moment to chat.</p>',
            '<p>Hi there, following up on our conversation from last week. Would love to hear your thoughts when you get a chance.</p>',
            '<p>Hope you\'re having a great week. I wanted to share a quick update and get your feedback on the next steps.</p>',
            '<p>Just a friendly follow-up. Let me know if the timing works for a quick call this week.</p>',
        ];
        return $bodies[array_rand($bodies)];
    }

    private function extractSearchTag(string $subject): string
    {
        if (preg_match('/\[PT-\d+-[a-f0-9]+\]/', $subject, $matches)) {
            return $matches[0];
        }
        return $subject;
    }

    private function detectProvider(string $email): ?string
    {
        $domain = strtolower(substr($email, strpos($email, '@') + 1));
        $map = [
            'gmail.com' => 'gmail', 'googlemail.com' => 'gmail',
            'outlook.com' => 'outlook', 'hotmail.com' => 'outlook', 'live.com' => 'outlook',
            'yahoo.com' => 'yahoo', 'ymail.com' => 'yahoo',
            'zoho.com' => 'zoho', 'zohomail.com' => 'zoho',
            'icloud.com' => 'icloud', 'me.com' => 'icloud',
            'aol.com' => 'aol',
            'protonmail.com' => 'protonmail', 'pm.me' => 'protonmail',
        ];
        return $map[$domain] ?? 'other';
    }
}
