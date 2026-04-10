<?php

namespace App\Services;

use App\Models\ContentFingerprint;
use App\Models\ContentTemplate;
use App\Models\SenderMailbox;
use App\Models\SeedMailbox;

class ContentGuardService
{
    /**
     * Generate a fingerprint hash from rendered content.
     * Normalizes whitespace and lowercases to catch near-duplicates.
     */
    public function generateFingerprint(string $content): string
    {
        $normalized = strtolower(trim(preg_replace('/\s+/', ' ', strip_tags($content))));
        return hash('sha256', $normalized);
    }

    /**
     * Check if this content (or very similar) was recently sent by this sender to this recipient.
     * Returns true if the content is safe to send (no duplicate detected).
     */
    public function isContentSafe(SenderMailbox $sender, string $recipientEmail, string $content, int $cooldownHours = 72): bool
    {
        $fingerprint = $this->generateFingerprint($content);

        $exists = ContentFingerprint::where('sender_mailbox_id', $sender->id)
            ->where('fingerprint_hash', $fingerprint)
            ->where('recipient_email', $recipientEmail)
            ->where('used_at', '>=', now()->subHours($cooldownHours))
            ->exists();

        return !$exists;
    }

    /**
     * Check if this sender has used this exact template too recently.
     * Prevents the same sender from sending the same template output pattern.
     */
    public function isTemplateCooledDown(SenderMailbox $sender, ContentTemplate $template): bool
    {
        if ($template->cooldown_minutes <= 0) {
            return true;
        }

        $recentUse = ContentFingerprint::where('sender_mailbox_id', $sender->id)
            ->where('content_template_id', $template->id)
            ->where('used_at', '>=', now()->subMinutes($template->cooldown_minutes))
            ->exists();

        return !$recentUse;
    }

    /**
     * Record that this content was sent.
     */
    public function recordUsage(SenderMailbox $sender, string $recipientEmail, string $content, ?ContentTemplate $template = null): void
    {
        ContentFingerprint::create([
            'sender_mailbox_id' => $sender->id,
            'content_template_id' => $template?->id,
            'fingerprint_hash' => $this->generateFingerprint($content),
            'recipient_email' => $recipientEmail,
            'used_at' => now(),
        ]);

        // Update template usage tracking
        if ($template) {
            $template->increment('usage_count');
            $template->update(['last_used_at' => now()]);
        }
    }

    /**
     * Check for content anti-patterns:
     * - Same fingerprint sent to multiple recipients within timeframe
     * - Same template used too many times in one day by same sender
     */
    public function checkAntiPatterns(SenderMailbox $sender, int $maxSameContentPerDay = 3): array
    {
        $warnings = [];

        // Check if any fingerprint was used more than allowed times today
        $duplicates = ContentFingerprint::where('sender_mailbox_id', $sender->id)
            ->where('used_at', '>=', today())
            ->selectRaw('fingerprint_hash, COUNT(*) as usage_count')
            ->groupBy('fingerprint_hash')
            ->having('usage_count', '>', $maxSameContentPerDay)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $warnings[] = [
                'type' => 'duplicate_content',
                'message' => "Sender {$sender->email_address} sent same content to {$duplicates->first()->usage_count} recipients today",
                'count' => $duplicates->count(),
            ];
        }

        // Check template diversity - warn if <3 unique templates used in last 24h
        $uniqueTemplates = ContentFingerprint::where('sender_mailbox_id', $sender->id)
            ->where('used_at', '>=', now()->subHours(24))
            ->whereNotNull('content_template_id')
            ->distinct('content_template_id')
            ->count('content_template_id');

        $totalSent = ContentFingerprint::where('sender_mailbox_id', $sender->id)
            ->where('used_at', '>=', now()->subHours(24))
            ->count();

        if ($totalSent > 3 && $uniqueTemplates < 3) {
            $warnings[] = [
                'type' => 'low_template_diversity',
                'message' => "Sender {$sender->email_address} used only {$uniqueTemplates} unique templates for {$totalSent} sends",
            ];
        }

        return $warnings;
    }

    /**
     * Clean old fingerprint records (older than 30 days).
     */
    public function cleanOldFingerprints(int $daysToKeep = 30): int
    {
        return ContentFingerprint::where('used_at', '<', now()->subDays($daysToKeep))->delete();
    }
}
