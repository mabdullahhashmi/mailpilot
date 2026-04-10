<?php

namespace App\Services;

use App\Models\BounceEvent;
use App\Models\SenderMailbox;
use App\Models\WarmupEvent;
use App\Models\MailboxHealthLog;
use App\Models\SystemAlert;
use Illuminate\Support\Facades\Log;

class BounceIntelligenceService
{
    /**
     * Classify and record a bounce event from SMTP response or DSN.
     */
    public function recordBounce(
        SenderMailbox $sender,
        string $recipientEmail,
        string $errorMessage,
        ?int $warmupEventId = null,
        ?int $threadId = null
    ): BounceEvent {
        $classification = $this->classifyBounce($errorMessage);

        $bounce = BounceEvent::create([
            'sender_mailbox_id' => $sender->id,
            'warmup_event_id' => $warmupEventId,
            'thread_id' => $threadId,
            'recipient_email' => $recipientEmail,
            'bounce_type' => $classification['type'],
            'bounce_code' => $classification['code'],
            'bounce_message' => substr($errorMessage, 0, 1000),
            'provider' => $this->detectProvider($recipientEmail),
            'bounced_at' => now(),
        ]);

        // Update daily health log with bounce type
        $this->updateHealthLogBounceType($sender, $classification['type']);

        // Auto-suppress on hard bounce
        if ($classification['type'] === 'hard') {
            $this->autoSuppress($bounce);
        }

        // Check if bounce pattern is alarming
        $this->checkBounceAlerts($sender);

        return $bounce;
    }

    /**
     * Classify bounce type from error message/code.
     */
    public function classifyBounce(string $errorMessage): array
    {
        $message = strtolower($errorMessage);
        $code = $this->extractSmtpCode($errorMessage);

        // Hard bounces - permanent delivery failure
        if ($this->matchesHardBounce($message, $code)) {
            return ['type' => 'hard', 'code' => $code];
        }

        // Policy bounces - blocked by policy
        if ($this->matchesPolicyBounce($message, $code)) {
            return ['type' => 'policy', 'code' => $code];
        }

        // Soft bounces - temporary failure, may succeed on retry
        if ($this->matchesSoftBounce($message, $code)) {
            return ['type' => 'soft', 'code' => $code];
        }

        // Transient bounces - infrastructure issues
        if ($this->matchesTransientBounce($message, $code)) {
            return ['type' => 'transient', 'code' => $code];
        }

        return ['type' => 'unknown', 'code' => $code];
    }

    /**
     * Get bounce analytics for a sender.
     */
    public function getSenderBounceAnalytics(SenderMailbox $sender, int $days = 30): array
    {
        $bounces = BounceEvent::where('sender_mailbox_id', $sender->id)
            ->where('bounced_at', '>=', now()->subDays($days))
            ->get();

        $total = $bounces->count();

        return [
            'total_bounces' => $total,
            'hard_bounces' => $bounces->where('bounce_type', 'hard')->count(),
            'soft_bounces' => $bounces->where('bounce_type', 'soft')->count(),
            'transient_bounces' => $bounces->where('bounce_type', 'transient')->count(),
            'policy_bounces' => $bounces->where('bounce_type', 'policy')->count(),
            'unknown_bounces' => $bounces->where('bounce_type', 'unknown')->count(),
            'suppressed_count' => $bounces->where('is_suppressed', true)->count(),
            'by_provider' => $bounces->groupBy('provider')->map->count()->toArray(),
            'daily_trend' => $this->getDailyBounceTrend($sender, $days),
            'top_codes' => $bounces->groupBy('bounce_code')
                ->map->count()
                ->sortDesc()
                ->take(10)
                ->toArray(),
        ];
    }

    /**
     * Get bounce summary across all senders (overview stats).
     */
    public function getOverallBounceStats(int $days = 7): array
    {
        $bounces = BounceEvent::where('bounced_at', '>=', now()->subDays($days))->get();

        $byType = $bounces->groupBy('bounce_type')->map->count();
        $byProvider = $bounces->groupBy('provider')->map->count();

        // Identify top offending senders
        $topSenders = $bounces->groupBy('sender_mailbox_id')
            ->map(function ($group) {
                return [
                    'count' => $group->count(),
                    'hard' => $group->where('bounce_type', 'hard')->count(),
                ];
            })
            ->sortByDesc('count')
            ->take(5);

        $senderDetails = [];
        foreach ($topSenders as $senderId => $stats) {
            $sender = SenderMailbox::find($senderId);
            if ($sender) {
                $senderDetails[] = [
                    'id' => $senderId,
                    'email' => $sender->email_address,
                    'total' => $stats['count'],
                    'hard' => $stats['hard'],
                ];
            }
        }

        return [
            'total' => $bounces->count(),
            'by_type' => $byType->toArray(),
            'by_provider' => $byProvider->toArray(),
            'top_offending_senders' => $senderDetails,
            'suppression_candidates' => $this->getSuppressionCandidates(),
        ];
    }

    /**
     * Get emails that should be suppressed (multiple hard bounces).
     */
    public function getSuppressionCandidates(): array
    {
        return BounceEvent::where('bounce_type', 'hard')
            ->where('is_suppressed', false)
            ->select('recipient_email')
            ->selectRaw('COUNT(*) as bounce_count')
            ->selectRaw('MAX(bounced_at) as last_bounce')
            ->groupBy('recipient_email')
            ->havingRaw('COUNT(*) >= 2')
            ->orderByDesc('bounce_count')
            ->take(50)
            ->get()
            ->map(fn ($r) => [
                'email' => $r->recipient_email,
                'bounces' => $r->bounce_count,
                'last_bounce' => $r->last_bounce,
            ])
            ->toArray();
    }

    /**
     * Suppress all bounces for a recipient email.
     */
    public function suppressEmail(string $email): int
    {
        return BounceEvent::where('recipient_email', $email)
            ->where('is_suppressed', false)
            ->update(['is_suppressed' => true]);
    }

    /**
     * Get root cause summary for bounces (grouped analysis).
     */
    public function getRootCauseSummary(int $days = 7): array
    {
        $bounces = BounceEvent::where('bounced_at', '>=', now()->subDays($days))
            ->get();

        $causes = [];

        // Group by provider + bounce type for pattern analysis
        $providerGroups = $bounces->groupBy(function ($b) {
            return ($b->provider ?? 'unknown') . ':' . $b->bounce_type;
        });

        foreach ($providerGroups as $key => $group) {
            [$provider, $type] = explode(':', $key);
            $causes[] = [
                'provider' => $provider,
                'bounce_type' => $type,
                'count' => $group->count(),
                'sample_codes' => $group->pluck('bounce_code')->filter()->unique()->take(5)->values()->toArray(),
                'recommendation' => $this->getRecommendation($type, $provider, $group->count()),
            ];
        }

        usort($causes, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $causes;
    }

    // ── Private Classification Methods ──

    private function matchesHardBounce(string $message, ?string $code): bool
    {
        $hardPatterns = [
            'user unknown', 'no such user', 'does not exist', 'mailbox not found',
            'invalid recipient', 'recipient rejected', 'address rejected',
            'account disabled', 'account has been disabled', 'account inactive',
            'no mailbox here', 'user not found', 'unknown user',
        ];
        foreach ($hardPatterns as $pattern) {
            if (str_contains($message, $pattern)) return true;
        }

        // SMTP 5.1.x codes are user-related permanent failures
        if ($code && (str_starts_with($code, '550') || str_starts_with($code, '551') || str_starts_with($code, '553'))) {
            return true;
        }

        return false;
    }

    private function matchesPolicyBounce(string $message, ?string $code): bool
    {
        $policyPatterns = [
            'blocked', 'blacklisted', 'denied', 'spam', 'rejected by policy',
            'rate limit', 'too many', 'reputation', 'dmarc', 'spf fail',
            'dkim fail', 'authentication', 'not authorized', 'policy',
            'message rejected', 'access denied', 'relay denied',
        ];
        foreach ($policyPatterns as $pattern) {
            if (str_contains($message, $pattern)) return true;
        }

        if ($code && (str_starts_with($code, '554') || str_starts_with($code, '571'))) {
            return true;
        }

        return false;
    }

    private function matchesSoftBounce(string $message, ?string $code): bool
    {
        $softPatterns = [
            'mailbox full', 'over quota', 'quota exceeded', 'insufficient storage',
            'try again', 'temporarily', 'temporarily rejected',
            'service unavailable', 'try later', 'busy',
        ];
        foreach ($softPatterns as $pattern) {
            if (str_contains($message, $pattern)) return true;
        }

        if ($code && str_starts_with($code, '452')) {
            return true;
        }

        return false;
    }

    private function matchesTransientBounce(string $message, ?string $code): bool
    {
        $transientPatterns = [
            'connection timed out', 'connection refused', 'dns failure',
            'network error', 'host not found', 'unable to connect',
            'no route to host', 'temporary failure',
        ];
        foreach ($transientPatterns as $pattern) {
            if (str_contains($message, $pattern)) return true;
        }

        if ($code && (str_starts_with($code, '421') || str_starts_with($code, '450'))) {
            return true;
        }

        return false;
    }

    private function extractSmtpCode(string $message): ?string
    {
        if (preg_match('/\b([245]\d{2})\b/', $message, $matches)) {
            return $matches[1];
        }
        return null;
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

    private function autoSuppress(BounceEvent $bounce): void
    {
        $priorHardBounces = BounceEvent::where('recipient_email', $bounce->recipient_email)
            ->where('bounce_type', 'hard')
            ->where('id', '!=', $bounce->id)
            ->count();

        if ($priorHardBounces >= 1) {
            BounceEvent::where('recipient_email', $bounce->recipient_email)
                ->where('is_suppressed', false)
                ->update(['is_suppressed' => true]);

            Log::info("[BounceIntel] Auto-suppressed {$bounce->recipient_email} after {$priorHardBounces}+ hard bounces");
        }
    }

    private function updateHealthLogBounceType(SenderMailbox $sender, string $bounceType): void
    {
        $log = MailboxHealthLog::firstOrCreate(
            ['sender_mailbox_id' => $sender->id, 'log_date' => today()],
            ['health_score' => 50, 'sends_today' => 0, 'replies_today' => 0, 'bounces_today' => 0]
        );

        if ($bounceType === 'hard') {
            $log->increment('hard_bounces');
        } elseif (in_array($bounceType, ['soft', 'transient'])) {
            $log->increment('soft_bounces');
        }
    }

    private function checkBounceAlerts(SenderMailbox $sender): void
    {
        $last24h = BounceEvent::where('sender_mailbox_id', $sender->id)
            ->where('bounced_at', '>=', now()->subHours(24))
            ->count();

        if ($last24h >= 5) {
            $existing = SystemAlert::where('context_type', 'sender_mailbox')
                ->where('context_id', $sender->id)
                ->where('title', 'like', '%bounce spike%')
                ->where('created_at', '>=', now()->subHours(6))
                ->exists();

            if (!$existing) {
                SystemAlert::create([
                    'title' => "Bounce spike: {$sender->email_address}",
                    'message' => "{$last24h} bounces in last 24 hours. Review bounce log for root cause.",
                    'severity' => $last24h >= 10 ? 'critical' : 'warning',
                    'context_type' => 'sender_mailbox',
                    'context_id' => $sender->id,
                ]);
            }
        }
    }

    private function getDailyBounceTrend(SenderMailbox $sender, int $days): array
    {
        return BounceEvent::where('sender_mailbox_id', $sender->id)
            ->where('bounced_at', '>=', now()->subDays($days))
            ->selectRaw('DATE(bounced_at) as date')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN bounce_type = 'hard' THEN 1 ELSE 0 END) as hard")
            ->selectRaw("SUM(CASE WHEN bounce_type = 'soft' THEN 1 ELSE 0 END) as soft")
            ->selectRaw("SUM(CASE WHEN bounce_type = 'policy' THEN 1 ELSE 0 END) as policy")
            ->groupByRaw('DATE(bounced_at)')
            ->orderBy('date')
            ->get()
            ->toArray();
    }

    private function getRecommendation(string $type, string $provider, int $count): string
    {
        return match ($type) {
            'hard' => "Remove invalid addresses. {$count} hard bounces from {$provider} — these recipients don't exist.",
            'soft' => "Mailbox full or temporary issues at {$provider}. Retry with reduced volume.",
            'policy' => "Blocked by {$provider} policy. Check SPF/DKIM/DMARC and sender reputation. Consider reducing send volume.",
            'transient' => "Network/DNS issues connecting to {$provider}. Usually resolves automatically.",
            default => "Unknown bounce pattern from {$provider}. Review SMTP error codes for details.",
        };
    }
}
